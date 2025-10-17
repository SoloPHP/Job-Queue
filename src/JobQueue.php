<?php

declare(strict_types=1);

namespace Solo\JobQueue;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Exception;
use JsonException;
use Throwable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use Solo\Contracts\JobQueue\JobInterface;
use Solo\Contracts\JobQueue\JobQueueInterface;

/**
 * Job Queue for managing asynchronous jobs.
 */
final readonly class JobQueue implements JobQueueInterface
{
    public function __construct(
        private Connection $connection,
        private string $table = 'jobs',
        private int $maxRetries = 3,
        private bool $deleteOnSuccess = false,
        private ?ContainerInterface $container = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Proxy to PSR-3 logger if provided.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Create jobs table if not exists.
     *
     * @throws Exception When database query fails
     * @throws \Doctrine\DBAL\Exception
     */
    public function install(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof SQLitePlatform) {
            $sql = "CREATE TABLE IF NOT EXISTS $this->table (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                type VARCHAR(64) NOT NULL DEFAULT 'default',
                payload TEXT NOT NULL,                
                scheduled_at DATETIME NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                retry_count INTEGER NOT NULL DEFAULT 0,
                error TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL,
                locked_at TIMESTAMP NULL,
                expires_at TIMESTAMP NULL
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS $this->table (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                payload JSON NOT NULL,
                type VARCHAR(64) NOT NULL DEFAULT 'default',
                scheduled_at DATETIME NOT NULL,
                status ENUM('pending', 'in_progress', 'completed', 'failed') NOT NULL DEFAULT 'pending',
                retry_count INT UNSIGNED NOT NULL DEFAULT 0,
                error TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                locked_at TIMESTAMP NULL DEFAULT NULL,
                expires_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_status_scheduled (status, scheduled_at),
                INDEX idx_locked_at (locked_at),
                INDEX idx_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }

        $this->connection->executeStatement($sql);
    }

    /**
     * Add a job to the queue.
     *
     * @param array<string, mixed> $payload Job data (must contain 'job_class' key)
     * @param DateTimeImmutable|null $scheduledAt When the job should be executed (default: now)
     * @param DateTimeImmutable|null $expiresAt When the job becomes invalid (optional)
     * @param string|null $type Job type for filtering (optional)
     * @return int ID of the newly inserted job
     * @throws JsonException When payload encoding fails
     * @throws Exception When database query fails
     * @throws \Doctrine\DBAL\Exception
     */
    public function addJob(
        array $payload,
        ?DateTimeImmutable $scheduledAt = null,
        ?DateTimeImmutable $expiresAt = null,
        ?string $type = null
    ): int {
        $scheduledAt ??= new DateTimeImmutable();

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $payloadType = $type ?? 'default';
        $name = $payload['job_class'] ?? 'unknown_job';

        $sql = "INSERT INTO $this->table (name, payload, type, scheduled_at, expires_at) " .
               "VALUES (?, ?, ?, ?, ?)";

        $this->connection->executeStatement($sql, [
            $name,
            $payloadJson,
            $payloadType,
            $scheduledAt->format('Y-m-d H:i:s'),
            $expiresAt?->format('Y-m-d H:i:s')
        ]);

        $this->log('info', 'Job added', ['id' => $this->connection->lastInsertId(), 'name' => $name]);

        return (int)$this->connection->lastInsertId();
    }

    /**
     * Retrieve pending jobs ready for execution.
     *
     * @param int $limit Maximum number of jobs to retrieve
     * @param string|null $onlyType If provided, only jobs with this type column value will be returned
     * @return array<int, array<string, mixed>> Array of job records as arrays
     * @throws Exception When database query fails
     * @throws \Doctrine\DBAL\Exception
     */
    public function getPendingJobs(int $limit = 10, ?string $onlyType = null): array
    {
        $now = new DateTimeImmutable();

        $sql = "SELECT * FROM $this->table WHERE status = 'pending' AND scheduled_at <= ? " .
               "AND (expires_at IS NULL OR expires_at > ?) AND locked_at IS NULL";

        if ($onlyType) {
            $sql .= " AND type = ? LIMIT " . $limit;
            $params = [
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
                $onlyType
            ];
        } else {
            $sql .= " LIMIT " . $limit;
            $params = [
                $now->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s')
            ];
        }

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * Lock a job for processing by updating its status and lock timestamp.
     *
     * @param int $jobId ID of the job to lock
     * @throws Exception When database query fails
     * @throws \Doctrine\DBAL\Exception
     */
    private function lockJob(int $jobId): void
    {
        $sql = "UPDATE $this->table SET locked_at = NOW(), status = 'in_progress' WHERE id = ?";
        $this->connection->executeStatement($sql, [$jobId]);
    }

    /**
     * Mark a job as completed or delete it based on configuration.
     *
     * @param int $jobId ID of the job
     * @throws Exception When database query fails
     * @throws \Doctrine\DBAL\Exception
     */
    public function markCompleted(int $jobId): void
    {
        if ($this->deleteOnSuccess) {
            $sql = "DELETE FROM $this->table WHERE id = ?";
        } else {
            $sql = "UPDATE $this->table SET status = 'completed', locked_at = NULL WHERE id = ?";
        }
        $this->connection->executeStatement($sql, [$jobId]);

        $this->log('info', 'Job completed', ['id' => $jobId]);
    }

    /**
     * Mark a job as failed and increment its retry counter.
     * If the retry count exceeds the max retry limit, the job is marked as 'failed';
     * otherwise, it is returned to 'pending'.
     *
     * @param int $jobId ID of the job
     * @param string $error Optional error message
     * @throws Exception When database query fails
     * @throws \Doctrine\DBAL\Exception
     */
    public function markFailed(int $jobId, string $error = ''): void
    {
        $sql = "UPDATE $this->table SET status = CASE WHEN retry_count >= ? THEN 'failed' ELSE 'pending' END, " .
               "retry_count = retry_count + 1, error = ?, locked_at = NULL WHERE id = ?";
        $this->connection->executeStatement($sql, [
            $this->maxRetries,
            $error,
            $jobId
        ]);

        $this->log('error', 'Job failed', ['id' => $jobId, 'error' => $error]);
    }

    /**
     * Push a job to the queue.
     *
     * @param JobInterface $job Job instance to queue
     * @param string|null $type Job type for filtering (optional)
     * @param DateTimeImmutable|null $scheduledAt When the job should be executed
     * @param DateTimeImmutable|null $expiresAt When the job becomes invalid
     * @return int ID of the newly inserted job
     * @throws JsonException When serialization fails
     * @throws Exception When database query fails
     * @throws \Doctrine\DBAL\Exception
     */
    public function push(
        JobInterface $job,
        ?string $type = null,
        ?DateTimeImmutable $scheduledAt = null,
        ?DateTimeImmutable $expiresAt = null
    ): int {
        $payload = [
            'job_class' => $job::class,
            'job_data' => json_encode($job, JSON_THROW_ON_ERROR)
        ];

        return $this->addJob($payload, $scheduledAt, $expiresAt, $type);
    }

    /**
     * Process pending jobs.
     *
     * @param int $limit Maximum number of jobs to process
     * @param string|null $onlyType If provided, only jobs with this type column value will be processed
     * @throws Exception When database operations fail
     * @throws \Doctrine\DBAL\Exception
     * @throws Throwable
     */
    public function processJobs(int $limit = 10, ?string $onlyType = null): void
    {
        $this->connection->beginTransaction();
        try {
            $jobs = $this->getPendingJobs($limit, $onlyType);
            $this->log('info', 'Processing jobs', ['count' => count($jobs)]);

            foreach ($jobs as $job) {
                /** @var array{id: int, payload: string} $job */
                try {
                    $this->lockJob($job['id']);
                    $payload = json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);
                    /** @var array<string, mixed> $payload */

                    if (isset($payload['job_class']) && isset($payload['job_data'])) {
                        $jobDataJson = $payload['job_data'];
                        assert(is_string($jobDataJson));
                        $jobData = json_decode($jobDataJson, true, 512, JSON_THROW_ON_ERROR);
                        /** @var array<string, mixed> $jobData */
                        $jobClass = $payload['job_class'];
                        assert(is_string($jobClass));

                        $this->log('info', 'Processing job', ['id' => $job['id'], 'class' => $jobClass]);

                        if (class_exists($jobClass) && is_subclass_of($jobClass, JobInterface::class)) {
                            // Try to create job via factory method if available
                            if ($this->container !== null && method_exists($jobClass, 'createFromContainer')) {
                                $jobInstance = $jobClass::createFromContainer($this->container, $jobData);
                            } else {
                                // Fallback to manual instantiation
                                $jobInstance = new $jobClass(...array_values($jobData));
                            }
                            assert($jobInstance instanceof JobInterface);
                            $jobInstance->handle();
                        }
                    }

                    $this->markCompleted($job['id']);
                } catch (Throwable $e) {
                    $this->markFailed($job['id'], $e->getMessage());
                }
            }

            $this->connection->commit();
            $this->log('info', 'All jobs processed');
        } catch (Throwable $e) {
            $this->connection->rollBack();
            $this->log('error', 'Error processing jobs', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
