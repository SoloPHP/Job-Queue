<?php

declare(strict_types=1);

namespace Solo\JobQueue;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use JsonSerializable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Solo\Contracts\JobQueue\JobInterface;
use Solo\Contracts\JobQueue\JobQueueInterface;
use Throwable;

/**
 * Database-backed job queue with atomic claim, visibility timeout and exponential backoff.
 */
final readonly class JobQueue implements JobQueueInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';
    private const MAX_RETRY_DELAY = 3600;

    public function __construct(
        private Connection $connection,
        private string $table = 'jobs',
        private int $maxRetries = 3,
        private int $lockTimeout = 600,
        private int $baseRetryDelay = 60,
        private bool $deleteOnSuccess = false,
        private ?ContainerInterface $container = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Push a typed job instance to the queue.
     *
     * @throws \JsonException
     * @throws \Doctrine\DBAL\Exception
     */
    public function push(
        JobInterface $job,
        ?string $type = null,
        ?DateTimeImmutable $scheduledAt = null,
        ?DateTimeImmutable $expiresAt = null
    ): int {
        $data = $job instanceof JsonSerializable ? $job->jsonSerialize() : [];

        $payload = [
            'job_class' => $job::class,
            'job_data'  => $data,
        ];

        return $this->addJob($payload, $scheduledAt, $expiresAt, $type);
    }

    /**
     * Add a raw-payload job to the queue. The payload MUST contain a 'job_class' key.
     *
     * @param array<string, mixed> $payload
     * @throws \JsonException
     * @throws \Doctrine\DBAL\Exception
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

        $scheduledAt ??= new DateTimeImmutable();

        $this->connection->executeStatement(
            "INSERT INTO {$this->table} (name, type, payload, scheduled_at, expires_at) "
            . "VALUES (?, ?, ?, ?, ?)",
            [
                $name,
                $type ?? 'default',
                json_encode($payload, JSON_THROW_ON_ERROR),
                $scheduledAt->format(self::DATE_FORMAT),
                $expiresAt?->format(self::DATE_FORMAT),
            ]
        );

        $id = (int) $this->connection->lastInsertId();
        $this->log('info', 'Job queued', ['id' => $id, 'name' => $name, 'type' => $type ?? 'default']);

        return $id;
    }

    /**
     * Return pending jobs ready for execution. Informational read — does not lock rows.
     *
     * @return array<int, array<string, mixed>>
     * @throws \Doctrine\DBAL\Exception
     */
    public function getPendingJobs(int $limit = 10, ?string $onlyType = null): array
    {
        $now = $this->now();

        $sql = "SELECT * FROM {$this->table} "
            . "WHERE status = 'pending' "
            . "AND scheduled_at <= ? "
            . "AND (expires_at IS NULL OR expires_at > ?) "
            . "AND locked_at IS NULL";

        $params = [$now, $now];
        if ($onlyType !== null) {
            $sql .= " AND type = ?";
            $params[] = $onlyType;
        }

        $sql .= " ORDER BY scheduled_at LIMIT " . max(0, $limit);

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * Claim pending jobs and run them. Stuck jobs are reclaimed before each batch.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function processJobs(int $limit = 10, ?string $onlyType = null): void
    {
        $this->reclaimStuck();

        $claimed = $this->claim($limit, $onlyType);

        if ($claimed === []) {
            return;
        }

        $this->log('info', 'Processing claimed jobs', ['count' => count($claimed)]);

        foreach ($claimed as $job) {
            $this->run($job);
        }
    }

    /**
     * Mark a job as completed (or delete it when deleteOnSuccess is enabled).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function markCompleted(int $jobId): void
    {
        if ($this->deleteOnSuccess) {
            $this->connection->executeStatement(
                "DELETE FROM {$this->table} WHERE id = ?",
                [$jobId]
            );
        } else {
            $this->connection->executeStatement(
                "UPDATE {$this->table} SET status = 'completed', locked_at = NULL, error = NULL WHERE id = ?",
                [$jobId]
            );
        }

        $this->log('info', 'Job completed', ['id' => $jobId]);
    }

    /**
     * Mark a job as failed. Retries are rescheduled with exponential backoff;
     * after maxRetries is reached the job is permanently marked as 'failed'.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function markFailed(int $jobId, string $error = ''): void
    {
        $current = $this->connection->fetchOne(
            "SELECT retry_count FROM {$this->table} WHERE id = ?",
            [$jobId]
        );

        if (!is_numeric($current)) {
            return;
        }

        $nextRetry = (int) $current + 1;

        if ($nextRetry >= $this->maxRetries) {
            $this->connection->executeStatement(
                "UPDATE {$this->table} "
                . "SET status = 'failed', retry_count = ?, error = ?, locked_at = NULL "
                . "WHERE id = ?",
                [$nextRetry, $error, $jobId]
            );
            $this->log('error', 'Job permanently failed', ['id' => $jobId, 'error' => $error]);
            return;
        }

        $nextRun = $this->backoffAt($nextRetry);

        $this->connection->executeStatement(
            "UPDATE {$this->table} "
            . "SET status = 'pending', retry_count = ?, error = ?, locked_at = NULL, scheduled_at = ? "
            . "WHERE id = ?",
            [$nextRetry, $error, $nextRun, $jobId]
        );

        $this->log('warning', 'Job failed, will retry', [
            'id'        => $jobId,
            'retry'     => $nextRetry,
            'next_run'  => $nextRun,
            'error'     => $error,
        ]);
    }

    /**
     * Atomically claim up to $limit pending jobs.
     *
     * @return array<int, array<string, mixed>>
     */
    private function claim(int $limit, ?string $type): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $this->connection->transactional(function (Connection $conn) use ($limit, $type): array {
            $now = $this->now();

            $sql = "SELECT id FROM {$this->table} "
                . "WHERE status = 'pending' "
                . "AND scheduled_at <= ? "
                . "AND (expires_at IS NULL OR expires_at > ?)";

            $params = [$now, $now];
            if ($type !== null) {
                $sql .= " AND type = ?";
                $params[] = $type;
            }

            $sql .= " ORDER BY scheduled_at LIMIT " . $limit;

            if (!$this->isSqlite()) {
                $sql .= " FOR UPDATE SKIP LOCKED";
            }

            $ids = [];
            foreach ($conn->fetchFirstColumn($sql, $params) as $raw) {
                if (is_numeric($raw)) {
                    $ids[] = (int) $raw;
                }
            }

            if ($ids === []) {
                return [];
            }

            $conn->executeStatement(
                "UPDATE {$this->table} SET status = 'in_progress', locked_at = ? WHERE id IN (?)",
                [$now, $ids],
                [ParameterType::STRING, ArrayParameterType::INTEGER]
            );

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $conn->fetchAllAssociative(
                "SELECT id, name, payload FROM {$this->table} WHERE id IN (?) ORDER BY scheduled_at",
                [$ids],
                [ArrayParameterType::INTEGER]
            );

            return $rows;
        });
    }

    /**
     * Return jobs whose worker died (locked_at older than lockTimeout) back to
     * pending, or mark them as failed if retries are exhausted.
     */
    private function reclaimStuck(): void
    {
        $staleBefore = (new DateTimeImmutable())
            ->modify("-{$this->lockTimeout} seconds")
            ->format(self::DATE_FORMAT);

        $types = [ParameterType::STRING, ParameterType::INTEGER];

        // Jobs with exhausted retries → failed.
        $failed = $this->connection->executeStatement(
            "UPDATE {$this->table} "
            . "SET status = 'failed', "
            . "    retry_count = retry_count + 1, "
            . "    error = 'Worker died (lock timeout)', "
            . "    locked_at = NULL "
            . "WHERE status = 'in_progress' "
            . "  AND locked_at IS NOT NULL AND locked_at < ? "
            . "  AND retry_count + 1 >= ?",
            [$staleBefore, $this->maxRetries],
            $types
        );

        // Jobs with retries remaining → pending.
        $requeued = $this->connection->executeStatement(
            "UPDATE {$this->table} "
            . "SET status = 'pending', "
            . "    retry_count = retry_count + 1, "
            . "    error = 'Worker died (lock timeout)', "
            . "    locked_at = NULL "
            . "WHERE status = 'in_progress' "
            . "  AND locked_at IS NOT NULL AND locked_at < ? "
            . "  AND retry_count + 1 < ?",
            [$staleBefore, $this->maxRetries],
            $types
        );

        $total = (int) $failed + (int) $requeued;
        if ($total > 0) {
            $this->log('warning', 'Reclaimed stuck jobs', [
                'requeued' => (int) $requeued,
                'failed'   => (int) $failed,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function run(array $job): void
    {
        $rawId      = $job['id']      ?? null;
        $rawPayload = $job['payload'] ?? null;

        if (!is_numeric($rawId) || !is_string($rawPayload)) {
            return;
        }

        $id = (int) $rawId;

        try {
            $instance = $this->instantiate($rawPayload);
            $this->log('info', 'Running job', ['id' => $id, 'class' => $instance::class]);
            $instance->handle();
            $this->markCompleted($id);
        } catch (Throwable $e) {
            $this->log('error', 'Job threw exception', [
                'id'    => $id,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($id, $e->getMessage());
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
        $delay = min($this->baseRetryDelay * (2 ** $retry), self::MAX_RETRY_DELAY);
        return (new DateTimeImmutable())
            ->modify("+{$delay} seconds")
            ->format(self::DATE_FORMAT);
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format(self::DATE_FORMAT);
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, $message, $context);
    }
}
