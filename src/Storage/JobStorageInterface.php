<?php

declare(strict_types=1);

namespace Solo\JobQueue\Storage;

use DateTimeImmutable;
use Solo\JobQueue\ClaimedJob;
use Solo\JobQueue\JobRecord;
use Solo\JobQueue\JobStatus;
use Solo\JobQueue\RetryPolicy;

/**
 * Persistence used by JobQueue. Implementations apply conditional state transitions;
 * JobQueue decides every next state. Acknowledgements are fenced by the attempt token.
 *
 * @internal Not covered by the backward compatibility promise; it may change in any 2.x release.
 */
interface JobStorageInterface
{
    public function insert(
        string $name,
        string $type,
        string $payload,
        DateTimeImmutable $scheduledAt,
        ?DateTimeImmutable $expiresAt,
        RetryPolicy $retryPolicy,
        DateTimeImmutable $now,
    ): int;

    /** @param list<array{0: string, 1: string}> $rows Pairs of job name and payload. */
    public function insertMany(
        array $rows,
        string $type,
        DateTimeImmutable $scheduledAt,
        ?DateTimeImmutable $expiresAt,
        RetryPolicy $retryPolicy,
        DateTimeImmutable $now,
    ): int;

    /** Atomically start one attempt. No attempt may be returned to concurrent consumers. */
    public function claim(?string $type, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): ?ClaimedJob;

    /** Complete, or delete, the job while this attempt's token is current. */
    public function complete(ClaimedJob $job, DateTimeImmutable $now, bool $delete): bool;

    /** Move the attempt to pending at $runAt, or to failed/expired, while its token is current. */
    public function fail(
        ClaimedJob $job,
        JobStatus $status,
        string $error,
        DateTimeImmutable $now,
        ?DateTimeImmutable $runAt,
    ): bool;

    /** Like fail(), but only while the attempt still holds the expired lease it was read with. */
    public function reclaim(
        ClaimedJob $job,
        JobStatus $status,
        string $error,
        DateTimeImmutable $now,
        ?DateTimeImmutable $runAt,
    ): bool;

    /** Extend a still-owned, unexpired lease; never shortens or revives it. */
    public function renew(ClaimedJob $job, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): bool;

    /** @return list<ClaimedJob> Attempts whose lease ended at or before $now, oldest first. */
    public function expiredLeases(DateTimeImmutable $now, int $limit): array;

    /** Move pending jobs whose deadline passed to the terminal expired state. */
    public function expirePending(DateTimeImmutable $now, string $error): int;

    /**
     * Requeue a failed job, or a failed/expired job when a new deadline is supplied.
     * Completed and active jobs are never revived.
     */
    public function retry(int $id, DateTimeImmutable $now, ?DateTimeImmutable $expiresAt): bool;

    /** @return list<JobRecord> Jobs ready to be claimed, in claim order. */
    public function pending(int $limit, ?string $type, DateTimeImmutable $now): array;

    /** @return list<JobRecord> Permanently failed jobs, newest first. */
    public function failed(int $limit, ?string $type): array;

    /** @return array{pending: int, in_progress: int, completed: int, failed: int, expired: int} */
    public function countByStatus(?string $type): array;

    /** @param non-empty-list<JobStatus> $statuses Terminal statuses to delete. */
    public function prune(DateTimeImmutable $finishedBefore, array $statuses): int;
}
