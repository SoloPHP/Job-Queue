<?php

declare(strict_types=1);

namespace Solo\JobQueue;

/**
 * Restart limits for {@see Worker}. Each limit is opt-in (0 = unlimited).
 * When any limit is reached the worker exits cleanly so a supervisor
 * (systemd, Supervisord, Kubernetes) can restart the process — which is
 * how PHP releases memory and picks up freshly deployed code.
 */
final readonly class WorkerLimits
{
    public function __construct(
        public int $maxJobs = 0,
        public int $maxRuntime = 0,
        public int $maxMemoryMb = 0,
    ) {
    }

    /**
     * Loop forever — only signal or `Worker::stop()` ends the run.
     */
    public static function unlimited(): self
    {
        return new self();
    }

    /**
     * Return the reason key for the first limit reached, or null if none.
     * Reasons: 'max_jobs', 'max_runtime', 'max_memory'.
     */
    public function reachedReason(int $totalProcessed, int $startedAt): ?string
    {
        if ($this->maxJobs > 0 && $totalProcessed >= $this->maxJobs) {
            return 'max_jobs';
        }
        if ($this->maxRuntime > 0 && (time() - $startedAt) >= $this->maxRuntime) {
            return 'max_runtime';
        }
        if ($this->maxMemoryMb > 0 && ((int) (memory_get_usage(true) / 1048576)) >= $this->maxMemoryMb) {
            return 'max_memory';
        }
        return null;
    }
}
