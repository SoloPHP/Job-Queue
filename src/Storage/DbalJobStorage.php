<?php

declare(strict_types=1);

namespace Solo\JobQueue\Storage;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;
use RuntimeException;
use Solo\JobQueue\ClaimedJob;
use Solo\JobQueue\JobRecord;
use Solo\JobQueue\JobStatus;
use Solo\JobQueue\RetryPolicy;
use Solo\JobQueue\Schema;

/**
 * Doctrine DBAL persistence. Consumer transitions are single conditional statements fenced by
 * the attempt token. Statements aborted by transient lock conflicts are retried.
 */
final class DbalJobStorage implements JobStorageInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';
    private const MAX_TRIES = 5;
    private const PRUNE_BATCH = 1000;
    private const INSERT = '(name, type, payload, scheduled_at, expires_at, max_attempts, base_delay, max_delay, '
        . 'created_at) VALUES ';
    private const ROW_PLACEHOLDERS = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
    private const CLAIM_COLUMNS = 'id, name, payload, attempts, claim_token, lease_until, '
        . 'max_attempts, base_delay, max_delay, expires_at';
    private const RECORD_COLUMNS = 'id, name, type, status, attempts, max_attempts, error, payload, '
        . 'scheduled_at, expires_at, created_at, finished_at';
    private const READY = "status = 'pending' AND scheduled_at <= ? "
        . 'AND (expires_at IS NULL OR expires_at > ?) AND attempts < max_attempts';
    private const OWNED = " WHERE id = ? AND claim_token = ? AND status = 'in_progress'";

    private ?string $quotedTable = null;

    public function __construct(private readonly Connection $connection, private readonly string $table = 'jobs')
    {
        Schema::assertValidTableName($table);
    }

    public function insert(
        string $name,
        string $type,
        string $payload,
        DateTimeImmutable $scheduledAt,
        ?DateTimeImmutable $expiresAt,
        RetryPolicy $retryPolicy,
        DateTimeImmutable $now,
    ): int {
        $sql = "INSERT INTO {$this->table()} " . self::INSERT . self::ROW_PLACEHOLDERS;
        $params = $this->row($name, $type, $payload, $scheduledAt, $expiresAt, $retryPolicy, $now);

        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return $this->integer(['id' => $this->connection->fetchOne($sql . ' RETURNING id', $params)], 'id');
        }

        $this->connection->executeStatement($sql, $params);
        return (int) $this->connection->lastInsertId();
    }

    public function insertMany(
        array $rows,
        string $type,
        DateTimeImmutable $scheduledAt,
        ?DateTimeImmutable $expiresAt,
        RetryPolicy $retryPolicy,
        DateTimeImmutable $now,
    ): int {
        if ($rows === []) {
            return 0;
        }
        $params = [];
        foreach ($rows as [$name, $payload]) {
            array_push($params, ...$this->row($name, $type, $payload, $scheduledAt, $expiresAt, $retryPolicy, $now));
        }
        return (int) $this->connection->executeStatement(
            "INSERT INTO {$this->table()} " . self::INSERT
            . implode(', ', array_fill(0, count($rows), self::ROW_PLACEHOLDERS)),
            $params,
        );
    }

    public function claim(?string $type, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): ?ClaimedJob
    {
        if ($leaseUntil <= $now) {
            throw new InvalidArgumentException('The lease deadline must be later than now.');
        }
        $this->assertAutocommit();
        $table = $this->table();
        $token = bin2hex(random_bytes(16));
        $lease = $this->format($leaseUntil);
        $filter = self::READY;
        $params = [$this->format($now), $this->format($now)];
        if ($type !== null) {
            $filter .= ' AND type = ?';
            $params[] = $type;
        }
        $platform = $this->connection->getDatabasePlatform();
        $start = "UPDATE {$table} SET status = 'in_progress', attempts = attempts + 1, "
            . 'claim_token = ?, lease_until = ?';

        $row = $this->retrying(function () use ($platform, $table, $filter, $params, $token, $lease, $start) {
            if (!$platform instanceof AbstractMySQLPlatform) {
                // A single statement: PostgreSQL row locks or the SQLite write lock make it atomic.
                $lock = $platform instanceof PostgreSQLPlatform ? ' FOR UPDATE SKIP LOCKED' : '';
                return $this->connection->fetchAssociative(
                    "{$start} WHERE id = (SELECT id FROM {$table} WHERE {$filter} "
                    . "ORDER BY scheduled_at, id LIMIT 1{$lock}) AND status = 'pending' "
                    . 'RETURNING ' . self::CLAIM_COLUMNS,
                    [$token, $lease, ...$params],
                );
            }
            // READ COMMITTED for this transaction only. Under REPEATABLE READ the scan takes gap locks
            // on the status index, and moving the claimed row within that index deadlocks other claims.
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            return $this->connection->transactional(function (Connection $connection) use (
                $table,
                $filter,
                $params,
                $token,
                $lease,
                $start,
            ): array|false {
                $row = $connection->fetchAssociative(
                    'SELECT id, name, payload, attempts, max_attempts, base_delay, max_delay, expires_at '
                    . "FROM {$table} WHERE {$filter} ORDER BY scheduled_at, id LIMIT 1 FOR UPDATE SKIP LOCKED",
                    $params,
                );
                if ($row === false) {
                    return false;
                }
                $connection->executeStatement("{$start} WHERE id = ?", [$token, $lease, $row['id']]);
                return ['attempts' => $this->integer($row, 'attempts') + 1, 'claim_token' => $token,
                    'lease_until' => $lease] + $row;
            });
        });

        return $row === false ? null : $this->claimed($row);
    }

    public function complete(ClaimedJob $job, DateTimeImmutable $now, bool $delete): bool
    {
        $this->assertAutocommit();
        [$sql, $params] = $delete
            ? ["DELETE FROM {$this->table()}", []]
            : ["UPDATE {$this->table()} SET status = 'completed', error = NULL, claim_token = NULL, "
                . 'lease_until = NULL, finished_at = ?', [$this->format($now)]];
        return $this->retrying(fn(): int => (int) $this->connection->executeStatement(
            $sql . self::OWNED,
            [...$params, $job->id, $job->token],
        )) > 0;
    }

    public function fail(
        ClaimedJob $job,
        JobStatus $status,
        string $error,
        DateTimeImmutable $now,
        ?DateTimeImmutable $runAt,
    ): bool {
        return $this->release($job, $status, $error, $now, $runAt, '', []);
    }

    public function reclaim(
        ClaimedJob $job,
        JobStatus $status,
        string $error,
        DateTimeImmutable $now,
        ?DateTimeImmutable $runAt,
    ): bool {
        // Exactly the lease that was read and found expired: a concurrent renewal wins.
        return $this->release($job, $status, $error, $now, $runAt, ' AND lease_until = ? AND lease_until <= ?', [
            $this->format($job->leaseUntil),
            $this->format($now),
        ]);
    }

    public function renew(ClaimedJob $job, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): bool
    {
        if ($leaseUntil <= $now) {
            throw new InvalidArgumentException('The lease deadline must be later than now.');
        }
        $this->assertAutocommit();
        $owned = self::OWNED . ' AND lease_until > ?';
        $lease = $this->format($leaseUntil);
        $params = [$job->id, $job->token, $this->format($now)];
        return $this->retrying(function () use ($owned, $lease, $params): bool {
            $updated = $this->connection->executeStatement(
                "UPDATE {$this->table()} SET lease_until = CASE WHEN lease_until < ? THEN ? ELSE lease_until END"
                . $owned,
                [$lease, $lease, ...$params],
            );
            // MySQL reports changed rows, so an idempotent renewal may return zero despite ownership.
            return $updated > 0
                || $this->connection->fetchOne("SELECT id FROM {$this->table()}" . $owned, $params) !== false;
        });
    }

    public function expiredLeases(DateTimeImmutable $now, int $limit): array
    {
        $this->assertAutocommit();
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::CLAIM_COLUMNS . " FROM {$this->table()} "
            . "WHERE status = 'in_progress' AND lease_until <= ? ORDER BY lease_until, id LIMIT " . max(0, $limit),
            [$this->format($now)],
        );
        return array_map($this->claimed(...), $rows);
    }

    public function expirePending(DateTimeImmutable $now, string $error): int
    {
        $this->assertAutocommit();
        $time = $this->format($now);
        return $this->retrying(fn(): int => (int) $this->connection->executeStatement(
            "UPDATE {$this->table()} SET status = 'expired', error = ?, finished_at = ? "
            . "WHERE status = 'pending' AND expires_at <= ?",
            [$error, $time, $time],
        ));
    }

    public function retry(int $id, DateTimeImmutable $now, ?DateTimeImmutable $expiresAt): bool
    {
        $sql = "UPDATE {$this->table()} SET status = 'pending', attempts = 0, error = NULL, finished_at = NULL, "
            . 'scheduled_at = ?';
        $params = [$this->format($now)];
        if ($expiresAt === null) {
            $sql .= " WHERE id = ? AND status = 'failed' AND (expires_at IS NULL OR expires_at > ?)";
            array_push($params, $id, $this->format($now));
        } else {
            $sql .= ", expires_at = ? WHERE id = ? AND status IN ('failed', 'expired')";
            array_push($params, $this->format($expiresAt), $id);
        }
        return $this->connection->executeStatement($sql, $params) > 0;
    }

    public function pending(int $limit, ?string $type, DateTimeImmutable $now): array
    {
        $sql = 'SELECT ' . self::RECORD_COLUMNS . " FROM {$this->table()} WHERE " . self::READY;
        $params = [$this->format($now), $this->format($now)];
        if ($type !== null) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }
        return $this->records($sql . ' ORDER BY scheduled_at, id LIMIT ' . max(0, $limit), $params);
    }

    public function failed(int $limit, ?string $type): array
    {
        $sql = 'SELECT ' . self::RECORD_COLUMNS . " FROM {$this->table()} WHERE status = 'failed'";
        $params = [];
        if ($type !== null) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }
        return $this->records($sql . ' ORDER BY id DESC LIMIT ' . max(0, $limit), $params);
    }

    public function countByStatus(?string $type): array
    {
        $stats = [];
        foreach (JobStatus::cases() as $status) {
            $stats[$status->value] = 0;
        }
        $sql = "SELECT status, COUNT(*) AS count FROM {$this->table()}";
        $params = [];
        if ($type !== null) {
            $sql .= ' WHERE type = ?';
            $params[] = $type;
        }
        foreach ($this->connection->fetchAllAssociative($sql . ' GROUP BY status', $params) as $row) {
            $status = $row['status'] ?? null;
            if (is_string($status) && isset($stats[$status])) {
                $stats[$status] = $this->integer($row, 'count');
            }
        }
        /** @var array{pending: int, in_progress: int, completed: int, failed: int, expired: int} $stats */
        return $stats;
    }

    public function prune(DateTimeImmutable $finishedBefore, array $statuses): int
    {
        $this->assertAutocommit();
        $filter = 'status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ') AND finished_at < ?';
        $params = [...array_map(static fn(JobStatus $status): string => $status->value, $statuses),
            $this->format($finishedBefore)];
        $deleted = 0;
        do {
            // Bounded batches keep each delete short instead of locking the whole history at once.
            $ids = $this->connection->fetchFirstColumn(
                "SELECT id FROM {$this->table()} WHERE {$filter} LIMIT " . self::PRUNE_BATCH,
                $params,
            );
            if ($ids === []) {
                break;
            }
            $deleted += $this->retrying(fn(): int => (int) $this->connection->executeStatement(
                "DELETE FROM {$this->table()} WHERE id IN (" . implode(', ', array_fill(0, count($ids), '?'))
                . ") AND {$filter}",
                [...$ids, ...$params],
            ));
        } while (count($ids) === self::PRUNE_BATCH);
        return $deleted;
    }

    /** @param list<string> $guardParams */
    private function release(
        ClaimedJob $job,
        JobStatus $status,
        string $error,
        DateTimeImmutable $now,
        ?DateTimeImmutable $runAt,
        string $guard,
        array $guardParams,
    ): bool {
        $finished = match ($status) {
            JobStatus::Pending => false,
            JobStatus::Failed, JobStatus::Expired => true,
            default => throw new InvalidArgumentException("An attempt cannot be released as {$status->value}."),
        };
        if ($finished === ($runAt !== null)) {
            throw new InvalidArgumentException('Exactly the pending state requires a next run time.');
        }
        $this->assertAutocommit();
        $sql = "UPDATE {$this->table()} SET status = ?, error = ?, scheduled_at = COALESCE(?, scheduled_at), "
            . 'finished_at = ?, claim_token = NULL, lease_until = NULL' . self::OWNED . $guard;
        $params = [
            $status->value,
            $error,
            $runAt === null ? null : $this->format($runAt),
            $finished ? $this->format($now) : null,
            $job->id,
            $job->token,
            ...$guardParams,
        ];
        return $this->retrying(fn(): int => (int) $this->connection->executeStatement($sql, $params)) > 0;
    }

    /**
     * Repeat a statement the database aborted for a transient lock conflict (deadlock, lock wait
     * timeout, busy SQLite). Only for consumer operations: they run in autocommit, and repeating
     * a token-guarded transition cannot apply it twice.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function retrying(callable $operation): mixed
    {
        for ($try = 1;; ++$try) {
            try {
                return $operation();
            } catch (RetryableException $error) {
                if ($try >= self::MAX_TRIES) {
                    throw $error;
                }
                usleep(10_000 * $try);
            }
        }
    }

    private function assertAutocommit(): void
    {
        if ($this->connection->isTransactionActive() || !$this->connection->isAutoCommit()) {
            throw new RuntimeException(
                'Queue consumers require a connection in autocommit without an active transaction.',
            );
        }
    }

    /** Resolved on first use: detecting the platform may open a connection. */
    private function table(): string
    {
        return $this->quotedTable ??= Schema::quoteTable($this->connection, $this->table);
    }

    /** @return list<int|string|null> */
    private function row(
        string $name,
        string $type,
        string $payload,
        DateTimeImmutable $scheduledAt,
        ?DateTimeImmutable $expiresAt,
        RetryPolicy $retryPolicy,
        DateTimeImmutable $now,
    ): array {
        return [
            $name,
            $type,
            $payload,
            $this->format($scheduledAt),
            $expiresAt === null ? null : $this->format($expiresAt),
            $retryPolicy->maxAttempts,
            $retryPolicy->baseDelay,
            $retryPolicy->maxDelay,
            $this->format($now),
        ];
    }

    private function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }

    /**
     * @param list<mixed> $params
     * @return list<JobRecord>
     */
    private function records(string $sql, array $params): array
    {
        return array_map($this->record(...), $this->connection->fetchAllAssociative($sql, $params));
    }

    /** @param array<string, mixed> $row */
    private function claimed(array $row): ClaimedJob
    {
        return new ClaimedJob(
            id: $this->integer($row, 'id'),
            name: $this->string($row, 'name'),
            payload: $this->string($row, 'payload'),
            attempt: $this->integer($row, 'attempts'),
            token: $this->string($row, 'claim_token'),
            leaseUntil: $this->date($row, 'lease_until'),
            retryPolicy: new RetryPolicy(
                maxAttempts: $this->integer($row, 'max_attempts'),
                baseDelay: $this->integer($row, 'base_delay'),
                maxDelay: $this->integer($row, 'max_delay'),
            ),
            expiresAt: $this->nullableDate($row, 'expires_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function record(array $row): JobRecord
    {
        $error = $row['error'] ?? null;
        return new JobRecord(
            id: $this->integer($row, 'id'),
            name: $this->string($row, 'name'),
            type: $this->string($row, 'type'),
            status: JobStatus::from($this->string($row, 'status')),
            attempts: $this->integer($row, 'attempts'),
            maxAttempts: $this->integer($row, 'max_attempts'),
            error: is_string($error) ? $error : null,
            payload: $this->string($row, 'payload'),
            scheduledAt: $this->date($row, 'scheduled_at'),
            expiresAt: $this->nullableDate($row, 'expires_at'),
            createdAt: $this->date($row, 'created_at'),
            finishedAt: $this->nullableDate($row, 'finished_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        if (!isset($row[$key]) || !is_numeric($row[$key])) {
            throw new RuntimeException("Invalid integer column {$key} in stored job.");
        }
        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new RuntimeException("Invalid string column {$key} in stored job.");
        }
        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function date(array $row, string $key): DateTimeImmutable
    {
        return new DateTimeImmutable($this->string($row, $key), new DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $row */
    private function nullableDate(array $row, string $key): ?DateTimeImmutable
    {
        return ($row[$key] ?? null) === null ? null : $this->date($row, $key);
    }
}
