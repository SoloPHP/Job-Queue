<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use RuntimeException;
use Solo\JobQueue\JobQueue;
use Solo\JobQueue\RetryPolicy;
use Solo\JobQueue\Tests\Fixtures\FailingJob;
use Solo\JobQueue\Tests\Fixtures\SpyLogger;

final class LoggerInteractionTest extends QueueTestCase
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
            storage:   $this->storage,
            retry:     new RetryPolicy(maxRetries: 1, baseDelay: 1),
            container: $this->container,
            logger:    $this->logger,
        );

        $queue->push(new FailingJob('boom'));
        $queue->processJobs();

        $perm = $this->logger->byMessage('Job permanently failed');
        $this->assertCount(1, $perm);
        $this->assertInstanceOf(RuntimeException::class, $perm[0]['context']['exception'] ?? null);
        $this->assertArrayNotHasKey('error', $perm[0]['context']);
    }

    public function testRetryLogAlsoCarriesThrowable(): void
    {
        $queue = new JobQueue(
            storage:   $this->storage,
            retry:     new RetryPolicy(maxRetries: 3, baseDelay: 1),
            container: $this->container,
            logger:    $this->logger,
        );

        $queue->push(new FailingJob('boom'));
        $queue->processJobs();

        $retry = $this->logger->byMessage('Job failed, will retry');
        $this->assertCount(1, $retry);
        $this->assertInstanceOf(RuntimeException::class, $retry[0]['context']['exception'] ?? null);
    }
}
