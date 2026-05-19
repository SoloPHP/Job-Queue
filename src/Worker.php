<?php

declare(strict_types=1);

namespace Solo\JobQueue;

use Psr\Log\LoggerInterface;
use Solo\Contracts\JobQueue\JobQueueInterface;

/**
 * Long-running worker daemon. Loops on `processJobs()`, sleeps when the queue
 * is empty, and shuts down gracefully on SIGTERM / SIGINT. Optional
 * {@see WorkerLimits} let you run under a supervisor that restarts the
 * process — important for releasing PHP memory and picking up freshly
 * deployed code.
 *
 * In production keep `$sleepWhenEmpty >= 1`; setting it to 0 turns the empty-
 * queue path into a CPU-bound tight loop that hammers the database.
 *
 * For very high-frequency processing also consider `JobQueue(autoReclaim:false)`
 * + a dedicated reclaim cron so reclaim doesn't run on every worker tick.
 */
final class Worker
{
    private bool $shouldStop = false;
    private readonly WorkerLimits $limits;

    public function __construct(
        private readonly JobQueueInterface $queue,
        private readonly int $batchSize = 10,
        private readonly ?string $type = null,
        private readonly int $sleepWhenEmpty = 1,
        ?WorkerLimits $limits = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->limits = $limits ?? new WorkerLimits();
    }

    public function run(): void
    {
        $this->installSignalHandlers();

        $startedAt = time();
        $totalProcessed = 0;

        $this->logger?->info('Worker started', [
            'batch'       => $this->batchSize,
            'type'        => $this->type,
            'max_jobs'    => $this->limits->maxJobs,
            'max_runtime' => $this->limits->maxRuntime,
            'max_memory'  => $this->limits->maxMemoryMb,
        ]);

        while (!$this->shouldStop) {
            $processed = $this->queue->processJobs($this->batchSize, $this->type);
            $totalProcessed += $processed;

            $reason = $this->limits->reachedReason($totalProcessed, $startedAt);
            if ($reason !== null) {
                $this->logger?->info('Worker stopping', ['reason' => $reason, 'processed' => $totalProcessed]);
                break;
            }

            if ($processed === 0) {
                sleep($this->sleepWhenEmpty);
            }
        }

        $this->logger?->info('Worker stopped', [
            'processed'   => $totalProcessed,
            'runtime_sec' => time() - $startedAt,
        ]);
    }

    /**
     * Request graceful shutdown. The current `processJobs()` batch finishes,
     * then the loop exits. Safe to call from signal handlers or tests.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->stop());
        pcntl_signal(SIGINT, fn() => $this->stop());
    }
}
