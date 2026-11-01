<?php

declare(strict_types=1);

namespace Solo\JobQueue;

use DateTimeImmutable;

/**
 * An execution attempt and its ownership token. A job ID alone cannot acknowledge an attempt.
 * Dates are UTC; JobQueue::renew() returns a copy carrying the extended lease.
 */
final readonly class ClaimedJob
{
    public function __construct(
        public int $id,
        public string $name,
        public string $payload,
        public int $attempt,
        public string $token,
        public DateTimeImmutable $leaseUntil,
        public RetryPolicy $retryPolicy,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
    }

    public function withLeaseUntil(DateTimeImmutable $leaseUntil): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->payload,
            $this->attempt,
            $this->token,
            $leaseUntil,
            $this->retryPolicy,
            $this->expiresAt,
        );
    }
}
