<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Solo\JobQueue\JobQueue;
use Solo\JobQueue\RetryPolicy;
use Solo\JobQueue\Tests\Fixtures\FailingJob;
use Solo\JobQueue\Tests\Fixtures\SimpleJob;
use Solo\JobQueue\Tests\Support\DatabaseTestCase;
use Solo\JobQueue\Tests\Support\FrozenClock;
use Solo\JobQueue\Tests\Support\SpyLogger;
use Stringable;

final class JobQueueLoggingTest extends DatabaseTestCase
{
    private SpyLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new SpyLogger();
    }

    public function testExceptionPassedUnderPsr3ExceptionKey(): void
    {
        $queue = new JobQueue(
            storage: $this->storage,
            codec: $this->codec(),
            retry: new RetryPolicy(maxAttempts: 1, baseDelay: 1),
            logger: $this->logger,
        );

        $queue->push(new FailingJob('boom'));
        $queue->processJobs();

        $perm = $this->logger->byMessage('Job permanently failed');
        $this->assertCount(1, $perm);
        $this->assertInstanceOf(RuntimeException::class, $perm[0]['context']['exception'] ?? null);
        $this->assertArrayNotHasKey('error', $perm[0]['context']);
    }

    public function testCompletionLoggerFailureDoesNotRescheduleSuccessfulJob(): void
    {
        $logger = new class extends AbstractLogger {
            public function log($level, Stringable|string $message, array $context = []): void
            {
                if ((string) $message === 'Job completed') {
                    throw new RuntimeException('logger unavailable');
                }
            }
        };
        $queue = new JobQueue($this->storage, $this->codec(), logger: $logger);
        $queue->push(new SimpleJob('once'));
        try {
            $queue->processJobs(1);
            $this->fail('Expected logger failure to propagate');
        } catch (RuntimeException $error) {
            $this->assertSame('logger unavailable', $error->getMessage());
        }
        $this->assertSame(1, $queue->getStats()['completed']);
        $this->assertSame(0, $queue->processJobs(1));
        $this->assertSame(['once'], $this->recorder->items);
    }

    public function testRetryLogAlsoCarriesThrowable(): void
    {
        $queue = new JobQueue(
            storage: $this->storage,
            codec: $this->codec(),
            retry: new RetryPolicy(maxAttempts: 3, baseDelay: 1),
            logger: $this->logger,
        );

        $queue->push(new FailingJob('boom'));
        $queue->processJobs();

        $retry = $this->logger->byMessage('Job failed, will retry');
        $this->assertCount(1, $retry);
        $this->assertInstanceOf(RuntimeException::class, $retry[0]['context']['exception'] ?? null);
    }

    public function testManualRetryIsLogged(): void
    {
        $queue = new JobQueue(
            storage: $this->storage,
            codec: $this->codec(),
            retry: new RetryPolicy(maxAttempts: 1),
            logger: $this->logger,
        );
        $id = $queue->push(new FailingJob('boom'));
        $queue->processJobs();

        $this->assertTrue($queue->retry($id));
        $this->assertFalse($queue->retry($id));
        $this->assertSame(
            [['level' => 'info', 'message' => 'Job re-queued', 'context' => ['id' => $id]]],
            $this->logger->byMessage('Job re-queued'),
        );
    }

    public function testMaintenanceLogsPendingJobsThatExpired(): void
    {
        $clock = new FrozenClock();
        $queue = new JobQueue($this->storage, $this->codec(), logger: $this->logger, clock: $clock);
        $queue->push(new SimpleJob('late'), expiresAt: $clock->now()->modify('+1 second'));
        $clock->advance(2);

        $this->assertSame(['requeued' => 0, 'failed' => 0, 'expired' => 1], $queue->maintain());
        $this->assertSame(
            [['level' => 'warning', 'message' => 'Pending jobs expired', 'context' => ['count' => 1]]],
            $this->logger->byMessage('Pending jobs expired'),
        );
    }
}
