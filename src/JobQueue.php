<?php

declare(strict_types=1);

namespace Solo\JobQueue;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Solo\Contracts\JobQueue\JobInterface;
use Solo\Contracts\JobQueue\JobQueueInterface;
use Solo\Contracts\JobQueue\JobQueueListener;
use Solo\JobQueue\Storage\JobStorageInterface;
use Throwable;

/**
 * Job queue with atomic claim, visibility timeout and exponential backoff.
 *
 * Pure orchestration: job instantiation, listener plumbing, retry policy.
 * Persistence is delegated to an injected {@see JobStorageInterface}
 * implementation — typically {@see \Solo\JobQueue\Storage\DbalJobStorage} for
 * RDBMS backends.
 *
 * All timestamps are stored in UTC regardless of the PHP default timezone —
 * use UTC for comparisons on the storage side too.
 */
final readonly class JobQueue implements JobQueueInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';
    private const DEFAULT_TYPE = 'default';
    private const RECLAIM_ERROR = 'Worker died (lock timeout)';
    private const PUSH_MANY_CHUNK = 1000;

    private DateTimeZone $utc;
    private RetryPolicy $retry;

    public function __construct(
        private JobStorageInterface $storage,
        ?RetryPolicy $retry = null,
        private bool $deleteOnSuccess = false,
        private ?ContainerInterface $container = null,
        private ?LoggerInterface $logger = null,
        private ?JobQueueListener $listener = null,
        private bool $autoReclaim = true,
    ) {
        $this->utc = new DateTimeZone('UTC');
        $this->retry = $retry ?? new RetryPolicy();
    }

    /**
     * Push a typed job instance to the queue.
     *
     * @throws \JsonException
     */
    public function push(
        JobInterface $job,
        ?string $type = null,
        ?DateTimeImmutable $scheduledAt = null,
        ?DateTimeImmutable $expiresAt = null
    ): int {
        return $this->addJob($this->buildPayload($job), $scheduledAt, $expiresAt, $type);
    }

    /**
     * Bulk-insert multiple jobs in a single statement. All jobs share the same
     * type / scheduled_at / expires_at. Returns the number of rows inserted.
     *
     * Internally chunks into batches of {@see self::PUSH_MANY_CHUNK} to stay
     * under PostgreSQL's 65535 bound-parameter limit and MySQL's
     * max_allowed_packet — callers can pass any size safely.
     *
     * @param list<JobInterface> $jobs
     * @throws \JsonException
     */
    public function pushMany(
        array $jobs,
        ?string $type = null,
        ?DateTimeImmutable $scheduledAt = null,
        ?DateTimeImmutable $expiresAt = null
    ): int {
        if ($jobs === []) {
            return 0;
        }

        $typeValue = $type ?? self::DEFAULT_TYPE;
        $scheduledAtUtc = $this->formatUtc($scheduledAt ?? new DateTimeImmutable('now', $this->utc));
        $expiresAtUtc = $expiresAt === null ? null : $this->formatUtc($expiresAt);

        $total = 0;
        foreach (array_chunk($jobs, self::PUSH_MANY_CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as $job) {
                $rows[] = [
                    $job::class,
                    json_encode($this->buildPayload($job), JSON_THROW_ON_ERROR),
                ];
            }
            $total += $this->storage->insertMany($rows, $typeValue, $scheduledAtUtc, $expiresAtUtc);
        }

        $this->log('info', 'Jobs queued', ['count' => $total, 'type' => $typeValue]);

        return $total;
    }

    /**
     * Add a raw-payload job to the queue. The payload MUST contain a 'job_class' key.
     *
     * @param array<string, mixed> $payload
     * @throws \JsonException
     */
    public function addJob(
        array $payload,
        ?DateTimeImmutable $scheduledAt = null,
        ?DateTimeImmutable $expiresAt = null,
        ?string $type = null
    ): int {
        $name = isset($payload['job_class']) && is_string($payload['job_class'])
            ? $payload['job_class']
            : 'unknown_job';

        $typeValue = $type ?? self::DEFAULT_TYPE;
        $scheduledAt ??= new DateTimeImmutable('now', $this->utc);

        $id = $this->storage->insert(
            $name,
            $typeValue,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $this->formatUtc($scheduledAt),
            $expiresAt === null ? null : $this->formatUtc($expiresAt),
        );

        $this->log('info', 'Job queued', ['id' => $id, 'name' => $name, 'type' => $typeValue]);

        return $id;
    }

    /**
     * Return pending jobs ready for execution. Informational read — does not lock rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPendingJobs(int $limit = 10, ?string $onlyType = null): array
    {
        return $this->storage->fetchPending($limit, $onlyType, $this->now());
    }

    /**
     * Claim pending jobs and run them. Stuck jobs are reclaimed before each
     * batch unless `autoReclaim = false` (then call `reclaimStuck()` yourself
     * from a separate cron).
     *
     * @return int Number of jobs actually claimed and executed in this call
     */
    public function processJobs(int $limit = 10, ?string $onlyType = null): int
    {
        if ($this->autoReclaim) {
            $this->reclaimStuck();
        }

        $claimed = $this->storage->claim($limit, $onlyType, $this->now());

        if ($claimed === []) {
            return 0;
        }

        $this->log('info', 'Processing claimed jobs', ['count' => count($claimed)]);

        foreach ($claimed as $job) {
            $this->run($job);
        }

        return count($claimed);
    }

    /**
     * Return counts grouped by status. All four status keys are always present
     * (missing statuses default to 0). Filter by type when monitoring a specific
     * workload — e.g. progress of a 'storefront-resync' batch.
     *
     * Without `$type` the query is a full-table scan. Pass `$type` for batch
     * progress, or rely on `deleteOnSuccess` to keep the table small.
     *
     * @return array{pending: int, in_progress: int, completed: int, failed: int}
     */
    public function getStats(?string $type = null): array
    {
        return $this->storage->countByStatus($type);
    }

    /**
     * Retrieve permanently-failed jobs for inspection. Most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFailedJobs(int $limit = 50, ?string $type = null): array
    {
        return $this->storage->fetchFailed($limit, $type);
    }

    /**
     * Re-queue a failed job: status → pending, retry_count reset to 0,
     * scheduled_at set to now, error cleared. Returns true if a row was
     * updated, false if no such job existed.
     *
     */
    public function retry(int $jobId): bool
    {
        $updated = $this->storage->updateToPending($jobId, 0, null, $this->now(), requireFailedStatus: true) > 0;
        if ($updated) {
            $this->log('info', 'Job re-queued', ['id' => $jobId]);
        }
        return $updated;
    }

    /**
     * Mark a job as completed (or delete it when deleteOnSuccess is enabled).
     *
     */
    public function markCompleted(int $jobId): void
    {
        if ($this->deleteOnSuccess) {
            $this->storage->delete($jobId);
        } else {
            $this->storage->markCompleted($jobId);
        }

        $this->log('info', 'Job completed', ['id' => $jobId]);
        $this->listener?->onCompleted($jobId);
    }

    /**
     * Mark a job as failed. Retries are rescheduled with exponential backoff;
     * after maxRetries is reached the job is permanently marked as 'failed'.
     *
     * Accepts a Throwable (recommended — full class/file/line captured in the
     * DB error column and forwarded to the logger under PSR-3 'exception' key)
     * or a plain string for manual failures.
     *
     */
    public function markFailed(int $jobId, Throwable|string $error = ''): void
    {
        $current = $this->storage->getRetryCount($jobId);
        if ($current === null) {
            return;
        }

        $errorText = $this->formatError($error);
        $logContext = $error instanceof Throwable
            ? ['exception' => $error]
            : ['error' => $errorText];

        $nextRetry = $current + 1;

        if ($nextRetry >= $this->retry->maxRetries) {
            $this->storage->updateToFailed($jobId, $nextRetry, $errorText);
            $this->log('error', 'Job permanently failed', ['id' => $jobId] + $logContext);
            $this->listener?->onFailed($jobId, $error, true);
            return;
        }

        $nextRun = $this->backoffAt($nextRetry);

        $this->storage->updateToPending($jobId, $nextRetry, $errorText, $nextRun);

        $this->log('warning', 'Job failed, will retry', [
            'id'       => $jobId,
            'retry'    => $nextRetry,
            'next_run' => $nextRun,
        ] + $logContext);
        $this->listener?->onFailed($jobId, $error, false);
    }

    /**
     * Return jobs whose worker died (locked_at older than lockTimeout) back to
     * pending, or mark them failed if retries are exhausted. Safe to call
     * standalone from a dedicated cron (e.g. once a minute) while another
     * process runs `processJobs()` with `autoReclaim = false`.
     *
     * @return array{requeued: int, failed: int}
     */
    public function reclaimStuck(): array
    {
        $staleBefore = $this->formatUtc(
            (new DateTimeImmutable('now', $this->utc))->modify("-{$this->retry->lockTimeout} seconds")
        );

        $result = $this->storage->reclaimStuck($this->retry->maxRetries, $staleBefore, self::RECLAIM_ERROR);

        if ($result['failed'] + $result['requeued'] > 0) {
            $this->log('warning', 'Reclaimed stuck jobs', $result);
            $this->listener?->onReclaimed($result['requeued'], $result['failed']);
        }

        return $result;
    }

    /**
     * @param array{id: int, name: string, payload: string} $job
     *
     */
    private function run(array $job): void
    {
        $id = $job['id'];

        try {
            $instance = $this->instantiate($job['payload']);
        } catch (Throwable $e) {
            $this->markFailed($id, $e);
            return;
        }

        $this->log('info', 'Running job', ['id' => $id, 'class' => $instance::class]);
        // Listener exceptions propagate by contract — keep this call outside the
        // handle()/markCompleted() try so a buggy listener doesn't fail the job.
        $this->listener?->onClaimed($id, $instance::class);

        try {
            $instance->handle();
            $this->markCompleted($id);
        } catch (Throwable $e) {
            $this->markFailed($id, $e);
        }
    }

    /**
     * Decode payload and instantiate a JobInterface via createFromContainer().
     */
    private function instantiate(string $payloadJson): JobInterface
    {
        /** @var mixed $payload */
        $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($payload) || !isset($payload['job_class']) || !is_string($payload['job_class'])) {
            throw new RuntimeException('Invalid job payload: missing job_class');
        }

        $jobClass = $payload['job_class'];
        $jobData  = isset($payload['job_data']) && is_array($payload['job_data'])
            ? $payload['job_data']
            : [];

        if (!class_exists($jobClass)) {
            throw new RuntimeException("Job class does not exist: {$jobClass}");
        }

        if (!is_subclass_of($jobClass, JobInterface::class)) {
            throw new RuntimeException("Class {$jobClass} does not implement " . JobInterface::class);
        }

        if (!method_exists($jobClass, 'createFromContainer')) {
            throw new RuntimeException(
                "Class {$jobClass} must define a static method "
                . "createFromContainer(ContainerInterface \$c, array \$data): self"
            );
        }

        if ($this->container === null) {
            throw new RuntimeException(
                "JobQueue requires a PSR-11 container to instantiate {$jobClass}"
            );
        }

        /** @var JobInterface $instance */
        $instance = $jobClass::createFromContainer($this->container, $jobData);

        return $instance;
    }

    private function backoffAt(int $retry): string
    {
        $delay = $this->retry->backoffSeconds($retry);
        return $this->formatUtc((new DateTimeImmutable('now', $this->utc))->modify("+{$delay} seconds"));
    }

    private function now(): string
    {
        return $this->formatUtc(new DateTimeImmutable('now', $this->utc));
    }

    private function formatUtc(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone($this->utc)->format(self::DATE_FORMAT);
    }

    /**
     * @return array{job_class: class-string<JobInterface>, job_data: mixed}
     */
    private function buildPayload(JobInterface $job): array
    {
        return [
            'job_class' => $job::class,
            'job_data'  => $job instanceof JsonSerializable ? $job->jsonSerialize() : [],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, $message, $context);
    }

    /**
     * Render a Throwable as "Class: message @ file:line" — enough for triage
     * without requiring a structured error column.
     */
    private function formatError(Throwable|string $error): string
    {
        if (!$error instanceof Throwable) {
            return $error;
        }

        return sprintf(
            '%s: %s @ %s:%d',
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
        );
    }
}
