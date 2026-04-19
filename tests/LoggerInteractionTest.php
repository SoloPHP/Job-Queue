<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Solo\JobQueue\JobQueue;
use Solo\JobQueue\Schema;
use Solo\JobQueue\Tests\Fixtures\ArrayContainer;
use Solo\JobQueue\Tests\Fixtures\FailingJob;
use Solo\JobQueue\Tests\Fixtures\Recorder;
use Solo\JobQueue\Tests\Fixtures\SpyLogger;

final class LoggerInteractionTest extends TestCase
{
    private Connection $connection;
    private SpyLogger $logger;
    private ArrayContainer $container;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        Schema::install($this->connection);

        $this->logger = new SpyLogger();
        $this->container = new ArrayContainer([Recorder::class => new Recorder()]);
    }

    public function testExceptionPassedUnderPsr3ExceptionKey(): void
    {
        $queue = new JobQueue(
            connection: $this->connection,
            maxRetries: 1,
            baseRetryDelay: 1,
            container: $this->container,
            logger: $this->logger,
        );

        $queue->push(new FailingJob('boom'));
        $queue->processJobs();

        $threw = $this->logger->byMessage('Job threw exception');
        $this->assertCount(1, $threw);
        $this->assertInstanceOf(RuntimeException::class, $threw[0]['context']['exception'] ?? null);
        $this->assertArrayNotHasKey('error', $threw[0]['context']);

        $perm = $this->logger->byMessage('Job permanently failed');
        $this->assertCount(1, $perm);
        $this->assertInstanceOf(RuntimeException::class, $perm[0]['context']['exception'] ?? null);
    }

    public function testRetryLogAlsoCarriesThrowable(): void
    {
        $queue = new JobQueue(
            connection: $this->connection,
            maxRetries: 3,
            baseRetryDelay: 1,
            container: $this->container,
            logger: $this->logger,
        );

        $queue->push(new FailingJob('boom'));
        $queue->processJobs();

        $retry = $this->logger->byMessage('Job failed, will retry');
        $this->assertCount(1, $retry);
        $this->assertInstanceOf(RuntimeException::class, $retry[0]['context']['exception'] ?? null);
    }
}
