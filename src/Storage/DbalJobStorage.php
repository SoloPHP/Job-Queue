<?php

declare(strict_types=1);

namespace Solo\JobQueue\Storage;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Solo\JobQueue\JobStatus;
use Solo\JobQueue\Schema;

/**
 * Doctrine DBAL implementation of {@see JobStorageInterface}. Contains every
 * SQL statement the package issues; the rest of `JobQueue` is pure
 * orchestration (listener plumbing, retry policy, job instantiation).
 *
 * Timestamps are passed in as already-formatted UTC strings — this class
 * does not know about timezones, it just executes SQL.
 */
final readonly class DbalJobStorage implements JobStorageInterface
{
    private const STATUS_PENDING = JobStatus::Pending;
    private const STATUS_IN_PROGRESS = JobStatus::InProgress;
    private const STATUS_COMPLETED = JobStatus::Completed;
    private const STATUS_FAILED = JobStatus::Failed;

    public function __construct(
        private Connection $connection,
        private string $table = 'jobs',
    ) {
        Schema::assertValidTableName($this->table);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function insert(
        string $name,
        string $type,
        string $payloadJson,
        string $scheduledAt,
        ?string $expiresAt,
    ): int {
        $this->connection->executeStatement(
            "INSERT INTO {$this->table} (name, type, payload, scheduled_at, expires_at) "
            . "VALUES (?, ?, ?, ?, ?)",
            [$name, $type, $payloadJson, $scheduledAt, $expiresAt]
        );

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param list<array{0: string, 1: string}> $rows
     * @throws \Doctrine\DBAL\Exception
     */
    public function insertMany(
        array $rows,
        string $type,
        string $scheduledAt,
        ?string $expiresAt,
    ): int {
        if ($rows === []) {
            return 0;
        }

        $placeholders = [];
        $params = [];
        foreach ($rows as [$name, $payloadJson]) {
            $placeholders[] = '(?, ?, ?, ?, ?)';
            $params[] = $name;
            $params[] = $type;
            $params[] = $payloadJson;
            $params[] = $scheduledAt;
            $params[] = $expiresAt;
        }

        $sql = "INSERT INTO {$this->table} (name, type, payload, scheduled_at, expires_at) "
            . "VALUES " . implode(', ', $placeholders);

        return (int) $this->connection->executeStatement($sql, $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws \Doctrine\DBAL\Exception
     */
    public function fetchPending(int $limit, ?string $type, string $now): array
    {
        $pending = self::STATUS_PENDING->value;
        $sql = "SELECT * FROM {$this->table} "
            . "WHERE status = '{$pending}' "
            . "AND scheduled_at <= ? "
            . "AND (expires_at IS NULL OR expires_at > ?) "
            . "AND locked_at IS NULL";

        $params = [$now, $now];
        if ($type !== null) {
            $sql .= " AND type = ?";
            $params[] = $type;
        }

        $sql .= " ORDER BY scheduled_at LIMIT " . max(0, $limit);

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * @return list<array{id: int, name: string, payload: string}>
     * @throws \Doctrine\DBAL\Exception
     */
    public function claim(int $limit, ?string $type, string $now): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $this->connection->transactional(function (Connection $conn) use ($limit, $type, $now): array {
            $pending = self::STATUS_PENDING->value;
            $inProgress = self::STATUS_IN_PROGRESS->value;

            $sql = "SELECT id FROM {$this->table} "
                . "WHERE status = '{$pending}' "
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
                "UPDATE {$this->table} SET status = '{$inProgress}', locked_at = ? WHERE id IN (?)",
                [$now, $ids],
                [ParameterType::STRING, ArrayParameterType::INTEGER]
            );

            $rows = $conn->fetchAllAssociative(
                "SELECT id, name, payload FROM {$this->table} WHERE id IN (?) ORDER BY scheduled_at",
                [$ids],
                [ArrayParameterType::INTEGER]
            );

            // Normalize driver-specific types: MySQL PDO returns stringified ints,
            // SQLite returns native ints. Narrow both to a guaranteed shape.
            $claimed = [];
            foreach ($rows as $row) {
                $rawId = $row['id'] ?? null;
                $rawName = $row['name'] ?? null;
                $rawPayload = $row['payload'] ?? null;
                if (!is_numeric($rawId) || !is_scalar($rawName) || !is_string($rawPayload)) {
                    continue;
                }
                $claimed[] = [
                    'id'      => (int) $rawId,
                    'name'    => (string) $rawName,
                    'payload' => $rawPayload,
                ];
            }
            return $claimed;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws \Doctrine\DBAL\Exception
     */
    public function fetchFailed(int $limit, ?string $type): array
    {
        $failed = self::STATUS_FAILED->value;
        $sql = "SELECT * FROM {$this->table} WHERE status = '{$failed}'";
        $params = [];
        if ($type !== null) {
            $sql .= " AND type = ?";
            $params[] = $type;
        }
        $sql .= " ORDER BY id DESC LIMIT " . max(0, $limit);

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * @return array{pending: int, in_progress: int, completed: int, failed: int}
     * @throws \Doctrine\DBAL\Exception
     */
    public function countByStatus(?string $type): array
    {
        $sql = "SELECT status, COUNT(*) AS count FROM {$this->table}";
        $params = [];
        if ($type !== null) {
            $sql .= " WHERE type = ?";
            $params[] = $type;
        }
        $sql .= " GROUP BY status";

        $stats = [
            self::STATUS_PENDING->value     => 0,
            self::STATUS_IN_PROGRESS->value => 0,
            self::STATUS_COMPLETED->value   => 0,
            self::STATUS_FAILED->value      => 0,
        ];

        foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
            $status = $row['status'] ?? null;
            $count = $row['count'] ?? null;
            if (is_string($status) && isset($stats[$status]) && is_numeric($count)) {
                $stats[$status] = (int) $count;
            }
        }

        /** @var array{pending: int, in_progress: int, completed: int, failed: int} $stats */
        return $stats;
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function delete(int $jobId): void
    {
        $this->connection->executeStatement(
            "DELETE FROM {$this->table} WHERE id = ?",
            [$jobId]
        );
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function markCompleted(int $jobId): void
    {
        $completed = self::STATUS_COMPLETED->value;
        $this->connection->executeStatement(
            "UPDATE {$this->table} SET status = '{$completed}', locked_at = NULL, error = NULL WHERE id = ?",
            [$jobId]
        );
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function getRetryCount(int $jobId): ?int
    {
        $current = $this->connection->fetchOne(
            "SELECT retry_count FROM {$this->table} WHERE id = ?",
            [$jobId]
        );

        return is_numeric($current) ? (int) $current : null;
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function updateToFailed(int $jobId, int $retryCount, string $error): void
    {
        $failed = self::STATUS_FAILED->value;
        $this->connection->executeStatement(
            "UPDATE {$this->table} "
            . "SET status = '{$failed}', retry_count = ?, error = ?, locked_at = NULL "
            . "WHERE id = ?",
            [$retryCount, $error, $jobId]
        );
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function updateToPending(
        int $jobId,
        int $retryCount,
        ?string $error,
        string $scheduledAt,
        bool $requireFailedStatus = false,
    ): int {
        $pending = self::STATUS_PENDING->value;
        $failed = self::STATUS_FAILED->value;

        $sql = "UPDATE {$this->table} "
            . "SET status = '{$pending}', retry_count = ?, error = ?, locked_at = NULL, scheduled_at = ? "
            . "WHERE id = ?"
            . ($requireFailedStatus ? " AND status = '{$failed}'" : '');

        return (int) $this->connection->executeStatement(
            $sql,
            [$retryCount, $error, $scheduledAt, $jobId]
        );
    }

    /**
     * Bulk UPDATEs by retry-count bucket so the caller can report how many
     * jobs were permanently failed vs. re-queued.
     *
     * @return array{requeued: int, failed: int}
     * @throws \Doctrine\DBAL\Exception
     */
    public function reclaimStuck(int $maxRetries, string $staleBefore, string $error): array
    {
        $failed = $this->updateStuckBucket(self::STATUS_FAILED, '>=', $maxRetries, $staleBefore, $error);
        $requeued = $this->updateStuckBucket(self::STATUS_PENDING, '<', $maxRetries, $staleBefore, $error);

        return ['requeued' => $requeued, 'failed' => $failed];
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private function updateStuckBucket(
        JobStatus $newStatus,
        string $retryCmp,
        int $maxRetries,
        string $staleBefore,
        string $error,
    ): int {
        $newStatusSql = $newStatus->value;
        $inProgress = self::STATUS_IN_PROGRESS->value;

        return (int) $this->connection->executeStatement(
            "UPDATE {$this->table} "
            . "SET status = '{$newStatusSql}', "
            . "    retry_count = retry_count + 1, "
            . "    error = ?, "
            . "    locked_at = NULL "
            . "WHERE status = '{$inProgress}' "
            . "  AND locked_at IS NOT NULL AND locked_at < ? "
            . "  AND retry_count + 1 {$retryCmp} ?",
            [$error, $staleBefore, $maxRetries],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]
        );
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }
}
