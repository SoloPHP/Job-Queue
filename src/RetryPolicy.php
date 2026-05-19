<?php

declare(strict_types=1);

namespace Solo\JobQueue;

/**
 * Retry behaviour for a {@see JobQueue}. Groups the three knobs that change
 * together (attempt cap, visibility timeout, backoff schedule) so they can
 * be passed around as a single value and reused across queue instances.
 *
 * Backoff schedule: `min(baseDelay * 2^retry, maxDelay)` — exponential with
 * a hard cap.
 */
final readonly class RetryPolicy
{
    public function __construct(
        public int $maxRetries = 3,
        public int $lockTimeout = 600,
        public int $baseDelay = 60,
        public int $maxDelay = 3600,
    ) {
    }

    /**
     * Many quick retries — for transient external services where most
     * failures resolve in seconds.
     */
    public static function aggressive(): self
    {
        return new self(maxRetries: 6, lockTimeout: 120, baseDelay: 5, maxDelay: 300);
    }

    /**
     * Few slow retries — for expensive operations or downstream services
     * with long recovery windows.
     */
    public static function lenient(): self
    {
        return new self(maxRetries: 10, lockTimeout: 1800, baseDelay: 300, maxDelay: 7200);
    }

    /**
     * Seconds to wait before the next attempt at `$retry` (1-based).
     */
    public function backoffSeconds(int $retry): int
    {
        return (int) min($this->baseDelay * (2 ** $retry), $this->maxDelay);
    }
}
