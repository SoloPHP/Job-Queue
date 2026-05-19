<?php

declare(strict_types=1);

namespace Solo\JobQueue\Storage;

/**
 * Internal persistence-layer contract for {@see \Solo\JobQueue\JobQueue}.
 *
 * This interface is NOT part of the public package contract — it lives here,
 * not in `solophp/contracts`, on purpose. The signatures reflect our concrete
 * schema (id/name/payload columns, 'pending'/'in_progress'/'completed'/'failed'
 * statuses, retry_count semantics) and are free to change between minor
 * versions of the queue package.
 *
 * If you need an alternative backend (Redis, SQS), implement
 * `Solo\Contracts\JobQueue\JobQueueInterface` directly with whatever
 * orchestration fits that transport. Do not force-fit it into this storage
 * contract.
 *
 * All timestamps are passed in as already-formatted strings in the storage's
 * native format (UTC `Y-m-d H:i:s` for SQL backends). This contract doesn't
 * touch timezones — that's the caller's responsibility.
 */
interface JobStorageInterface
{
    /**
     * Insert one job row. Returns the new id.
     */
    public function insert(
        string $name,
        string $type,
        string $payloadJson,
        string $scheduledAt,
        ?string $expiresAt,
    ): int;

    /**
     * Bulk-insert many rows. `$rows` is a list of `[name, payloadJson]` tuples;
     * all rows share `$type / $scheduledAt / $expiresAt`. Returns the number
     * of rows inserted.
     *
     * @param list<array{0: string, 1: string}> $rows
     */
    public function insertMany(
        array $rows,
        string $type,
        string $scheduledAt,
        ?string $expiresAt,
    ): int;

    /**
     * Informational read of pending jobs ready for execution. Does not lock rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchPending(int $limit, ?string $type, string $now): array;

    /**
     * Atomically claim up to $limit pending jobs: select, mark as 'in_progress',
     * return them. Implementations must guarantee that two concurrent claims
     * never return overlapping rows.
     *
     * @return list<array{id: int, name: string, payload: string}>
     */
    public function claim(int $limit, ?string $type, string $now): array;

    /**
     * Permanently-failed jobs, most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchFailed(int $limit, ?string $type): array;

    /**
     * Counts grouped by status. All four status keys are always present
     * (missing statuses default to 0).
     *
     * @return array{pending: int, in_progress: int, completed: int, failed: int}
     */
    public function countByStatus(?string $type): array;

    /**
     * Delete a job row (used when `deleteOnSuccess` is enabled).
     */
    public function delete(int $jobId): void;

    /**
     * Move a job to 'completed': clear locks and error.
     */
    public function markCompleted(int $jobId): void;

    /**
     * Read the current retry_count for a job. Returns null if the job doesn't exist.
     */
    public function getRetryCount(int $jobId): ?int;

    /**
     * Permanently fail a job: status='failed', record error, set retry_count.
     */
    public function updateToFailed(int $jobId, int $retryCount, string $error): void;

    /**
     * Return a job to 'pending'. Used both by markFailed's retry branch (with
     * the next backoff timestamp) and retry() (with retry_count reset). Pass
     * `requireFailedStatus: true` to make the update a no-op on non-failed jobs.
     *
     * @return int Number of affected rows
     */
    public function updateToPending(
        int $jobId,
        int $retryCount,
        ?string $error,
        string $scheduledAt,
        bool $requireFailedStatus = false,
    ): int;

    /**
     * Sweep stuck in_progress jobs whose locked_at is older than $staleBefore:
     * those with retries remaining return to 'pending', the rest become 'failed'.
     * $error is written into the error column for both buckets.
     *
     * @return array{requeued: int, failed: int}
     */
    public function reclaimStuck(int $maxRetries, string $staleBefore, string $error): array;
}
