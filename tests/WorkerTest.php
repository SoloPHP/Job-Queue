<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use Solo\JobQueue\JobQueue;
use Solo\JobQueue\Tests\Fixtures\SimpleJob;
use Solo\JobQueue\Worker;
use Solo\JobQueue\WorkerLimits;

final class WorkerTest extends QueueTestCase
{
    private JobQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new JobQueue(
            storage:   $this->storage,
            container: $this->container,
        );
    }

    public function testWorkerStopsAfterMaxJobsLimit(): void
    {
        foreach (range(1, 5) as $i) {
            $this->queue->push(new SimpleJob("J{$i}"));
        }

        $worker = new Worker(
            queue:          $this->queue,
            batchSize:      2,
            sleepWhenEmpty: 0,
            limits:         new WorkerLimits(maxJobs: 3),
        );

        $worker->run();

        $this->assertGreaterThanOrEqual(3, count($this->recorder->items));
    }

    public function testWorkerStopsAfterMaxRuntime(): void
    {
        // No jobs pushed. Worker will hit empty-sleep path until maxRuntime fires.
        $worker = new Worker(
            queue:          $this->queue,
            batchSize:      1,
            sleepWhenEmpty: 0,
            limits:         new WorkerLimits(maxRuntime: 1),
        );

        $start = time();
        $worker->run();
        $elapsed = time() - $start;

        $this->assertLessThan(3, $elapsed, 'Worker should exit shortly after maxRuntime');
    }

    public function testWorkerStopsAfterMaxMemoryLimit(): void
    {
        // PHPUnit + vendor + this test already use well over 1 MB, so the first
        // limit check trips immediately.
        $worker = new Worker(
            queue:          $this->queue,
            batchSize:      1,
            sleepWhenEmpty: 0,
            limits:         new WorkerLimits(maxMemoryMb: 1),
        );

        $start = time();
        $worker->run();

        $this->assertLessThan(2, time() - $start);
    }

    public function testWorkerStopsWhenStopRequested(): void
    {
        $worker = new Worker(
            queue:           $this->queue,
            batchSize:       1,
            sleepWhenEmpty:  0,
        );

        // Push a job whose handler triggers worker stop via the recorder closure.
        $this->queue->push(new SimpleJob('only'));
        $this->recorder->onItem = static function () use ($worker): void {
            $worker->stop();
        };

        $worker->run();

        $this->assertSame(['only'], $this->recorder->items);
    }
}
