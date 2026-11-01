<?php

declare(strict_types=1);

namespace Solo\JobQueue;

use DateTimeImmutable;
use Solo\Contracts\JobQueue\JobInterface;

/**
 * Enqueues jobs without exposing execution or administrative operations.
 * Depend on it in application services that only schedule work.
 */
interface JobProducerInterface
{
    /**
     * @return int ID of the stored job
     */
    public function push(
        JobInterface $job,
        ?string $type = null,
        ?DateTimeImmutable $scheduledAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): int;

    /**
     * @param list<JobInterface> $jobs
     * @return int Number of stored jobs
     */
    public function pushMany(
        array $jobs,
        ?string $type = null,
        ?DateTimeImmutable $scheduledAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): int;
}
