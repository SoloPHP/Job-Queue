<?php

declare(strict_types=1);

namespace Solo\JobQueue;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Solo\Contracts\JobQueue\JobInterface;
use Solo\Contracts\JobQueue\JobQueueListener;
use Solo\JobQueue\Storage\JobStorageInterface;
use Throwable;

/**
 * Database queue facade. A claim represents one attempt with exclusive, expiring ownership.
 * Every next state is decided here; the storage applies it as a token-guarded update.
 */
final readonly class JobQueue implements JobProducerInterface, JobProcessorInterface
{
    private const DEFAULT_TYPE = 'default';
    private const PUSH_MANY_CHUNK = 1000;
    private const RECLAIM_BATCH = 100;
    private const MAX_ERROR_BYTES = 16384;
    private const LEASE_EXPIRED = 'Worker lease expired';
    private const DEADLINE_PASSED = 'Job execution deadline passed';
    private const FINISHED = [JobStatus::Completed, JobStatus::Failed, JobStatus::Expired];

    private RetryPolicy $retry;
    private ClockInterface $clock;

    public function __construct(
        private JobStorageInterface $storage,
        private JobCodecInterface $codec,
        ?RetryPolicy $retry = null,
        private int $leaseDuration = 600,
        private bool $deleteOnSuccess = false,
        private ?LoggerInterface $logger = null,
        private ?JobQueueListener $listener = null,
        ?ClockInterface $clock = null,
    ) {
        if ($leaseDuration < 1) {
            throw new InvalidArgumentException('leaseDuration must be positive.');
        }
        $this->retry = $retry ?? new RetryPolicy();
        $this->clock = $clock ?? new SystemClock();
    }

    public function push(
        JobInterface $job,
        ?string $type = null,
        ?DateTimeImmutable $scheduledAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): int {
        $now = $this->clock->now();
        $type ??= self::DEFAULT_TYPE;
        $id = $this->storage->insert(
            $job::class,
            $type,
            $this->encode($job),
            $scheduledAt ?? $now,
            $expiresAt,
            $this->retry,
            $now,
        );
        $this->log('info', 'Job queued', ['id' => $id, 'name' => $job::class, 'type' => $type]);
        return $id;
    }

    /**
     * Bulk enqueue; each chunk is atomic. Earlier chunks remain committed if a later chunk fails.
     *
     * @param list<JobInterface> $jobs
     */
    public function pushMany(
        array $jobs,
        ?string $type = null,
        ?DateTimeImmutable $scheduledAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): int {
        $now = $this->clock->now();
        $type ??= self::DEFAULT_TYPE;
        $total = 0;
        foreach (array_chunk($jobs, self::PUSH_MANY_CHUNK) as $chunk) {
            $rows = array_map(fn(JobInterface $job): array => [$job::class, $this->encode($job)], $chunk);
            $total += $this->storage->insertMany($rows, $type, $scheduledAt ?? $now, $expiresAt, $this->retry, $now);
        }
        if ($total > 0) {
            $this->log('info', 'Jobs queued', ['count' => $total, 'type' => $type]);
        }
        return $total;
    }

    /**
     * Run maintenance once, then execute up to limit jobs, claiming each immediately before it runs.
     * Observer and persistence exceptions propagate without becoming handler failures.
     */
    public function processJobs(int $limit = 10, ?string $type = null): int
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('limit must be non-negative.');
        }
        if ($limit === 0) {
            return 0;
        }
        $this->maintain();
        $processed = 0;
        while ($processed < $limit && $this->processNext($type)) {
            ++$processed;
        }
        return $processed;
    }

    public function processNext(?string $type = null): bool
    {
        $job = $this->claim($type);
        if ($job === null) {
            return false;
        }
        $this->run($job);
        return true;
    }

    /**
     * Recover up to 100 attempts whose lease expired, using each job's saved policy, and move
     * pending jobs past their deadline to expired. Safe to run from several workers at once.
     */
    public function maintain(): array
    {
        $now = $this->clock->now();
        $reclaimed = ['requeued' => 0, 'failed' => 0, 'expired' => 0];
        foreach ($this->storage->expiredLeases($now, self::RECLAIM_BATCH) as $job) {
            [$status, $runAt] = $this->outcome($job, $now);
            if ($this->storage->reclaim($job, $status, self::LEASE_EXPIRED, $now, $runAt)) {
                ++$reclaimed[match ($status) {
                    JobStatus::Pending => 'requeued',
                    JobStatus::Failed => 'failed',
                    default => 'expired',
                }];
            }
        }
        if (array_sum($reclaimed) > 0) {
            $this->log('warning', 'Reclaimed stuck jobs', $reclaimed);
            $this->listener?->onReclaimed($reclaimed['requeued'], $reclaimed['failed'] + $reclaimed['expired']);
        }

        $expired = $this->storage->expirePending($now, self::DEADLINE_PASSED);
        if ($expired > 0) {
            $this->log('warning', 'Pending jobs expired', ['count' => $expired]);
        }

        return [
            'requeued' => $reclaimed['requeued'],
            'failed' => $reclaimed['failed'],
            'expired' => $reclaimed['expired'] + $expired,
        ];
    }

    /** Claim one job for manual execution. Complete, fail and renew require this claim's token. */
    public function claim(?string $type = null): ?ClaimedJob
    {
        $now = $this->clock->now();
        return $this->storage->claim($type, $now, $this->leaseDeadline($now));
    }

    /**
     * Accepted while this attempt still owns the job, even after its lease ended, until maintenance
     * reclaims it. False means the job was reclaimed; no completion event is emitted.
     */
    public function complete(ClaimedJob $job): bool
    {
        if (!$this->storage->complete($job, $this->clock->now(), $this->deleteOnSuccess)) {
            $this->log('warning', 'Job ownership lost before completion', ['id' => $job->id]);
            return false;
        }
        $this->log('info', 'Job completed', ['id' => $job->id]);
        $this->listener?->onCompleted($job->id);
        return true;
    }

    /** Same ownership rule as complete(). The retry follows the job's saved policy and deadline. */
    public function fail(ClaimedJob $job, Throwable|string $error): bool
    {
        $now = $this->clock->now();
        [$status, $runAt] = $this->outcome($job, $now);
        if (!$this->storage->fail($job, $status, $this->formatError($error), $now, $runAt)) {
            $this->log('warning', 'Job ownership lost before failure', ['id' => $job->id]);
            return false;
        }
        $permanent = $status !== JobStatus::Pending;
        $context = [
            'id' => $job->id,
            'attempt' => $job->attempt,
            'next_run' => $runAt?->format(DateTimeInterface::ATOM),
        ];
        $context += $error instanceof Throwable ? ['exception' => $error] : ['error' => $error];
        $this->log($permanent ? 'error' : 'warning', match ($status) {
            JobStatus::Expired => 'Job expired after failure',
            JobStatus::Failed => 'Job permanently failed',
            default => 'Job failed, will retry',
        }, $context);
        $this->listener?->onFailed($job->id, $error, $permanent);
        return true;
    }

    /**
     * Cooperative lease extension. Returns the claim with its extended lease, or null once the
     * lease has ended or another attempt owns the job: stop working on it then.
     */
    public function renew(ClaimedJob $job): ?ClaimedJob
    {
        $now = $this->clock->now();
        $leaseUntil = $this->leaseDeadline($now);
        if (!$this->storage->renew($job, $now, $leaseUntil)) {
            return null;
        }
        return $leaseUntil > $job->leaseUntil ? $job->withLeaseUntil($leaseUntil) : $job;
    }

    /** An expired job requires a new future deadline; attempts restart on an accepted retry. */
    public function retry(int $jobId, ?DateTimeImmutable $expiresAt = null): bool
    {
        $now = $this->clock->now();
        if ($expiresAt !== null && $expiresAt <= $now) {
            return false;
        }
        return $this->storage->retry($jobId, $now, $expiresAt);
    }

    /** @return list<JobRecord> Ready jobs in claim order; an informational read that claims nothing. */
    public function getPendingJobs(int $limit = 10, ?string $type = null): array
    {
        return $this->storage->pending($limit, $type, $this->clock->now());
    }

    /** @return list<JobRecord> Permanently failed jobs, newest first. */
    public function getFailedJobs(int $limit = 50, ?string $type = null): array
    {
        return $this->storage->failed($limit, $type);
    }

    /** @return array{pending: int, in_progress: int, completed: int, failed: int, expired: int} */
    public function getStats(?string $type = null): array
    {
        return $this->storage->countByStatus($type);
    }

    /**
     * Delete finished jobs whose finish time is before the cutoff, in bounded batches.
     *
     * @param list<JobStatus> $statuses Any of completed, failed and expired.
     * @return int Number of deleted jobs
     */
    public function prune(DateTimeImmutable $finishedBefore, array $statuses = [JobStatus::Completed]): int
    {
        if ($statuses === []) {
            throw new InvalidArgumentException('At least one status is required.');
        }
        foreach ($statuses as $status) {
            if (!in_array($status, self::FINISHED, true)) {
                throw new InvalidArgumentException("Only finished jobs can be pruned, not {$status->value}.");
            }
        }
        $deleted = $this->storage->prune($finishedBefore, $statuses);
        if ($deleted > 0) {
            $this->log('info', 'Pruned finished jobs', ['count' => $deleted]);
        }
        return $deleted;
    }

    private function run(ClaimedJob $job): void
    {
        try {
            $instance = $this->codec->decode($job->payload);
        } catch (Throwable $e) {
            $this->fail($job, $e);
            return;
        }
        $this->log('info', 'Running job', ['id' => $job->id, 'class' => $instance::class]);
        $this->listener?->onClaimed($job->id, $instance::class);
        // Starting after the lease ended would race with maintenance handing the job to another worker.
        if ($this->clock->now() >= $job->leaseUntil) {
            $this->fail($job, 'Lease expired before execution started');
            return;
        }
        try {
            $instance->handle();
        } catch (Throwable $e) {
            $this->fail($job, $e);
            return;
        }
        $this->complete($job);
    }

    /**
     * Where a failed or abandoned attempt goes next: the single decision used by fail() and maintain().
     *
     * @return array{JobStatus, ?DateTimeImmutable}
     */
    private function outcome(ClaimedJob $job, DateTimeImmutable $now): array
    {
        if ($job->expiresAt !== null && $job->expiresAt <= $now) {
            return [JobStatus::Expired, null];
        }
        if ($job->attempt >= $job->retryPolicy->maxAttempts) {
            return [JobStatus::Failed, null];
        }
        $runAt = $now->modify('+' . $job->retryPolicy->backoffSeconds($job->attempt) . ' seconds');
        // A retry due at or after the deadline could never run.
        if ($job->expiresAt !== null && $runAt >= $job->expiresAt) {
            return [JobStatus::Expired, null];
        }
        return [JobStatus::Pending, $runAt];
    }

    /** Whole seconds, matching the stored precision. */
    private function leaseDeadline(DateTimeImmutable $now): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . ($now->getTimestamp() + $this->leaseDuration));
    }

    private function encode(JobInterface $job): string
    {
        $payload = $this->codec->encode($job);
        if (!json_validate($payload)) {
            throw new InvalidArgumentException('JobCodecInterface::encode() must return valid JSON.');
        }
        return $payload;
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, $message, $context);
    }

    /** Valid UTF-8 without NUL bytes and of bounded size, so every supported database can store it. */
    private function formatError(Throwable|string $error): string
    {
        $text = $error instanceof Throwable
            ? sprintf('%s: %s @ %s:%d', $error::class, $error->getMessage(), $error->getFile(), $error->getLine())
            : $error;
        $text = str_replace("\0", '\0', mb_scrub($text, 'UTF-8'));
        return strlen($text) > self::MAX_ERROR_BYTES
            ? mb_strcut($text, 0, self::MAX_ERROR_BYTES - 3, 'UTF-8') . '...'
            : $text;
    }
}
