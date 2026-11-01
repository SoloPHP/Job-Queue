<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Solo\JobQueue\JobProcessorInterface;
use Solo\JobQueue\Worker;
use Solo\JobQueue\WorkerLimits;

final class WorkerTest extends TestCase
{
    public function testWorkerStopsAfterExactlyMaxJobs(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->expects($this->exactly(3))->method('processNext')->with('email')->willReturn(true);
        $processor->expects($this->once())->method('maintain');

        $worker = new Worker(
            queue: $processor,
            type: 'email',
            sleepWhenEmpty: 0,
            limits: new WorkerLimits(maxJobs: 3),
        );
        $worker->run();
    }

    public function testMaintenanceRunsBeforeEveryJobWhenTheIntervalIsZero(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->method('processNext')->willReturn(true);
        $processor->expects($this->exactly(3))->method('maintain');

        (new Worker(queue: $processor, limits: new WorkerLimits(maxJobs: 3), maintenanceInterval: 0))->run();
    }

    public function testMaintenanceRepeatsOnceTheIntervalHasPassed(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->method('processNext')->willReturnCallback(static function (): bool {
            usleep(300_000);
            return true;
        });
        $processor->expects($this->exactly(2))->method('maintain');

        (new Worker(queue: $processor, limits: new WorkerLimits(maxJobs: 5), maintenanceInterval: 1))->run();
    }

    public function testMaintenanceCanBeLeftToACron(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->method('processNext')->willReturn(true);
        $processor->expects($this->never())->method('maintain');

        (new Worker(queue: $processor, limits: new WorkerLimits(maxJobs: 3), maintenanceInterval: null))->run();
    }

    public function testWorkerStopsAfterMaxRuntime(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->expects($this->atLeastOnce())->method('processNext')->with(null)
            ->willReturnCallback(static function (): bool {
                usleep(20_000);
                return false;
            });

        $worker = new Worker(
            queue: $processor,
            sleepWhenEmpty: 0,
            limits: new WorkerLimits(maxRuntime: 1),
        );
        $startedAt = hrtime(true);
        $worker->run();
        $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->assertGreaterThanOrEqual(1, $elapsed);
        $this->assertLessThan(3, $elapsed);
    }

    public function testIdleSleepIsLimitedByMaxRuntime(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->expects($this->once())->method('processNext')->willReturn(false);

        $worker = new Worker(
            queue: $processor,
            sleepWhenEmpty: 10,
            limits: new WorkerLimits(maxRuntime: 1),
        );
        $startedAt = hrtime(true);
        $worker->run();

        $this->assertLessThan(3, (hrtime(true) - $startedAt) / 1_000_000_000);
    }

    public function testMemoryLimitIsCheckedBeforeClaimingAnotherJob(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->expects($this->never())->method('processNext');
        $processor->expects($this->never())->method('maintain');

        $worker = new Worker(queue: $processor, limits: new WorkerLimits(maxMemoryMb: 1));
        $worker->run();
    }

    public function testStopFinishesCurrentJobWithoutClaimingAnother(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $worker = new Worker(queue: $processor, sleepWhenEmpty: 0);
        $finished = false;
        $processor->expects($this->once())->method('processNext')->with(null)
            ->willReturnCallback(static function () use ($worker, &$finished): bool {
                $worker->stop();
                $finished = true;
                return true;
            });

        $worker->run();

        $this->assertTrue($finished);
    }

    public function testStopOnEmptyQueueDoesNotSleep(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $worker = new Worker(queue: $processor, sleepWhenEmpty: 10);
        $processor->expects($this->once())->method('processNext')
            ->willReturnCallback(static function () use ($worker): bool {
                $worker->stop();
                return false;
            });

        $startedAt = hrtime(true);
        $worker->run();

        $this->assertLessThan(1, (hrtime(true) - $startedAt) / 1_000_000_000);
    }

    public function testNegativeSleepIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Worker(queue: $this->createStub(JobProcessorInterface::class), sleepWhenEmpty: -1);
    }

    public function testNegativeMaintenanceIntervalIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Worker(queue: $this->createStub(JobProcessorInterface::class), maintenanceInterval: -1);
    }

    public function testProcessorExceptionsPropagate(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $error = new RuntimeException('Database unavailable');
        $processor->expects($this->once())->method('processNext')->willThrowException($error);
        $worker = new Worker(queue: $processor);

        $this->expectExceptionObject($error);
        $worker->run();
    }

    public function testSignalStateIsRestoredAfterNormalExit(): void
    {
        $this->assertSignalStateIsRestored(false);
    }

    public function testSignalStateIsRestoredAfterException(): void
    {
        $this->assertSignalStateIsRestored(true);
    }

    public function testSignalHandlingCanBeDisabled(): void
    {
        $this->requirePcntl();
        $termHandler = pcntl_signal_get_handler(SIGTERM);
        $intHandler = pcntl_signal_get_handler(SIGINT);
        $asyncSignals = pcntl_async_signals();

        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->expects($this->once())->method('processNext')
            ->willReturnCallback(function () use ($termHandler, $intHandler, $asyncSignals): bool {
                $this->assertSame($termHandler, pcntl_signal_get_handler(SIGTERM));
                $this->assertSame($intHandler, pcntl_signal_get_handler(SIGINT));
                $this->assertSame($asyncSignals, pcntl_async_signals());
                return true;
            });

        $worker = new Worker(queue: $processor, limits: new WorkerLimits(maxJobs: 1), handleSignals: false);
        $worker->run();
    }

    public function testSigtermStopsAfterCurrentJob(): void
    {
        $this->requirePcntl();
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('The posix extension is required for sending SIGTERM');
        }

        $processor = $this->createMock(JobProcessorInterface::class);
        $finished = false;
        $processor->expects($this->once())->method('processNext')
            ->willReturnCallback(function () use (&$finished): bool {
                $this->assertTrue(posix_kill(getmypid(), SIGTERM));
                pcntl_signal_dispatch();
                $finished = true;
                return true;
            });

        $worker = new Worker(queue: $processor, sleepWhenEmpty: 0);
        $worker->run();

        $this->assertTrue($finished);
    }

    private function assertSignalStateIsRestored(bool $throw): void
    {
        $this->requirePcntl();
        $previousTerm = pcntl_signal_get_handler(SIGTERM);
        $previousInt = pcntl_signal_get_handler(SIGINT);
        $previousAsync = pcntl_async_signals();
        $termHandler = static function (int $signal): void {
        };
        $intHandler = static function (int $signal): void {
        };
        pcntl_signal(SIGTERM, $termHandler);
        pcntl_signal(SIGINT, $intHandler);
        pcntl_async_signals(false);

        try {
            $processor = $this->createMock(JobProcessorInterface::class);
            $processor->expects($this->once())->method('processNext')
                ->willReturnCallback(function () use ($termHandler, $intHandler, $throw): bool {
                    $this->assertNotSame($termHandler, pcntl_signal_get_handler(SIGTERM));
                    $this->assertNotSame($intHandler, pcntl_signal_get_handler(SIGINT));
                    $this->assertTrue(pcntl_async_signals());
                    if ($throw) {
                        throw new RuntimeException('Processor failed');
                    }
                    return true;
                });
            $worker = new Worker(queue: $processor, limits: new WorkerLimits(maxJobs: 1));

            try {
                $worker->run();
                $this->assertFalse($throw, 'Processor exception must propagate');
            } catch (RuntimeException $error) {
                $this->assertTrue($throw);
                $this->assertSame('Processor failed', $error->getMessage());
            }

            $this->assertSame($termHandler, pcntl_signal_get_handler(SIGTERM));
            $this->assertSame($intHandler, pcntl_signal_get_handler(SIGINT));
            $this->assertFalse(pcntl_async_signals());
        } finally {
            pcntl_signal(SIGTERM, $previousTerm);
            pcntl_signal(SIGINT, $previousInt);
            pcntl_async_signals($previousAsync);
        }
    }

    private function requirePcntl(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('The pcntl extension is required');
        }
    }
}
