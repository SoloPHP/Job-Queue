<?php

declare(strict_types=1);

namespace Solo\JobQueue\Worker;

use Closure;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Solo\Contracts\JobQueue\JobProcessorInterface;

/**
 * Long-running worker daemon. Executes one job at a time with `processNext()`, runs
 * `maintain()` at start and then every `$maintenanceInterval` seconds, sleeps when the
 * queue is empty, and shuts down gracefully on SIGTERM / SIGINT. Optional
 * {@see WorkerLimits} let you run under a supervisor that restarts the
 * process — important for releasing PHP memory and picking up freshly
 * deployed code.
 *
 * In production keep `$sleepWhenEmpty >= 1`; setting it to 0 turns the empty-
 * queue path into a CPU-bound tight loop that hammers the database.
 *
 * Pass `maintenanceInterval: null` when a separate cron calls `JobQueue::maintain()`.
 */
final class Worker
{
    private bool $shouldStop = false;
    private readonly WorkerLimits $limits;

    public function __construct(
        private readonly JobProcessorInterface $queue,
        private readonly ?string $type = null,
        private readonly int $sleepWhenEmpty = 1,
        ?WorkerLimits $limits = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $handleSignals = true,
        private readonly ?int $maintenanceInterval = 60,
    ) {
        if ($sleepWhenEmpty < 0) {
            throw new InvalidArgumentException('sleepWhenEmpty must be non-negative');
        }
        if ($maintenanceInterval !== null && $maintenanceInterval < 0) {
            throw new InvalidArgumentException('maintenanceInterval must be non-negative or null');
        }
        $this->limits = $limits ?? new WorkerLimits();
    }

    public function run(): void
    {
        $restoreSignals = $this->installSignalHandlers();
        $startedAt = hrtime(true);
        $maintainedAt = null;
        $totalProcessed = 0;

        try {
            $this->logger?->info('Worker started', [
                'type' => $this->type,
                'max_jobs' => $this->limits->maxJobs,
                'max_runtime' => $this->limits->maxRuntime,
                'max_memory' => $this->limits->maxMemoryMb,
                'maintenance_interval' => $this->maintenanceInterval,
            ]);

            while (!$this->shouldStop) {
                $reason = $this->limits->reachedReason($totalProcessed, self::secondsSince($startedAt));
                if ($reason !== null) {
                    $this->logger?->info('Worker stopping', ['reason' => $reason, 'processed' => $totalProcessed]);
                    break;
                }

                if (
                    $this->maintenanceInterval !== null
                    && ($maintainedAt === null || self::secondsSince($maintainedAt) >= $this->maintenanceInterval)
                ) {
                    $this->queue->maintain();
                    $maintainedAt = hrtime(true);
                }

                if ($this->queue->processNext($this->type)) {
                    ++$totalProcessed;
                } elseif (!$this->stopRequested()) {
                    $sleepSeconds = $this->sleepWhenEmpty;
                    if ($this->limits->maxRuntime > 0) {
                        $remaining = $this->limits->maxRuntime - (int) self::secondsSince($startedAt);
                        $sleepSeconds = min($sleepSeconds, max(0, $remaining));
                    }
                    sleep($sleepSeconds);
                }
            }

            $this->logger?->info('Worker stopped', [
                'processed' => $totalProcessed,
                'runtime_sec' => self::secondsSince($startedAt),
            ]);
        } finally {
            $restoreSignals?->__invoke();
        }
    }

    /**
     * Request graceful shutdown after the current job finishes.
     * Safe to call from signal handlers or tests.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /** Signal handlers and processor callbacks may call stop() while a job runs. */
    private function stopRequested(): bool
    {
        return $this->shouldStop;
    }

    /** Monotonic, so wall-clock changes cannot shorten or extend limits and intervals. */
    private static function secondsSince(int|float $start): float
    {
        return (hrtime(true) - $start) / 1_000_000_000;
    }

    /** @return (Closure(): void)|null */
    private function installSignalHandlers(): ?Closure
    {
        if (!$this->handleSignals || !function_exists('pcntl_async_signals')) {
            return null;
        }

        $asyncSignals = pcntl_async_signals();
        $termHandler = pcntl_signal_get_handler(SIGTERM);
        $intHandler = pcntl_signal_get_handler(SIGINT);
        pcntl_signal(SIGTERM, fn() => $this->stop());
        pcntl_signal(SIGINT, fn() => $this->stop());
        pcntl_async_signals(true);

        return static function () use ($asyncSignals, $termHandler, $intHandler): void {
            pcntl_signal(SIGTERM, $termHandler);
            pcntl_signal(SIGINT, $intHandler);
            pcntl_async_signals($asyncSignals);
        };
    }
}
