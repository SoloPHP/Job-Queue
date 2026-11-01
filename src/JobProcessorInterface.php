<?php

declare(strict_types=1);

namespace Solo\JobQueue;

/**
 * Executes ready jobs without exposing producer or administrative operations.
 */
interface JobProcessorInterface
{
    /**
     * Run maintenance once, then execute up to $limit jobs.
     *
     * @return int Number of attempts executed, never greater than $limit
     */
    public function processJobs(int $limit = 10, ?string $type = null): int;

    /**
     * Claim and execute one job without running maintenance.
     *
     * @return bool False when no job was ready
     */
    public function processNext(?string $type = null): bool;

    /**
     * Recover attempts whose lease expired and expire overdue pending jobs.
     *
     * @return array{requeued: int, failed: int, expired: int}
     */
    public function maintain(): array;
}
