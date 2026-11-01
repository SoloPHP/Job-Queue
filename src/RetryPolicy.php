<?php

declare(strict_types=1);

namespace Solo\JobQueue;

use InvalidArgumentException;

/** Retry settings persisted with each job, independent of worker configuration. */
final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelay = 60,
        public int $maxDelay = 3600,
    ) {
        if ($maxAttempts < 1 || $baseDelay < 0 || $maxDelay < 0) {
            throw new InvalidArgumentException('maxAttempts must be positive; retry delays must be non-negative.');
        }
    }

    public static function aggressive(): self
    {
        return new self(maxAttempts: 6, baseDelay: 5, maxDelay: 300);
    }

    public static function lenient(): self
    {
        return new self(maxAttempts: 10, baseDelay: 300, maxDelay: 7200);
    }

    /** Delay after a failed attempt (1-based); the first delay is baseDelay. */
    public function backoffSeconds(int $attempt): int
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('Attempt must be positive.');
        }
        if ($this->baseDelay === 0 || $this->maxDelay === 0) {
            return 0;
        }
        $delay = min($this->baseDelay, $this->maxDelay);
        // Saturating arithmetic avoids integer overflow, even for a corrupt attempt counter.
        for ($i = 1; $i < $attempt && $delay < $this->maxDelay; ++$i) {
            if ($delay > intdiv($this->maxDelay, 2)) {
                return $this->maxDelay;
            }
            $delay *= 2;
        }
        return $delay;
    }
}
