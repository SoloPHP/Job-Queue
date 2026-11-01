<?php

declare(strict_types=1);

namespace Solo\JobQueue;

use DateTimeImmutable;

/** Snapshot of a stored job returned by the inspection methods. Dates are UTC. */
final readonly class JobRecord
{
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public JobStatus $status,
        public int $attempts,
        public int $maxAttempts,
        public ?string $error,
        public string $payload,
        public DateTimeImmutable $scheduledAt,
        public ?DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $finishedAt,
    ) {
    }
}
