<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\JobQueue\JobQueue;
use Solo\JobQueue\Schema;
use Solo\JobQueue\Tests\Fixtures\ArrayContainer;
use Solo\JobQueue\Tests\Fixtures\FailingJob;
use Solo\JobQueue\Tests\Fixtures\JobWithoutFactory;
use Solo\JobQueue\Tests\Fixtures\NotAJob;
use Solo\JobQueue\Tests\Fixtures\Recorder;
use Solo\JobQueue\Tests\Fixtures\SimpleJob;

final class JobQueueTest extends TestCase
{
    private Connection $connection;
    private Recorder $recorder;
    private ArrayContainer $container;
    private JobQueue $queue;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        Schema::install($this->connection);

        $this->recorder = new Recorder();
        $this->container = new ArrayContainer([Recorder::class => $this->recorder]);

        $this->queue = new JobQueue(
            connection: $this->connection,
            container: $this->container,
        );
    }

    public function testTypeFilteringRunsOnlyMatchingJobs(): void
    {
        $this->queue->push(new SimpleJob('A'), 'email');
        $this->queue->push(new SimpleJob('B'), 'webhook');

        $this->queue->processJobs(10, 'email');
        $this->assertSame(['A'], $this->recorder->items);

        $this->queue->processJobs(10, 'webhook');
        $this->assertSame(['A', 'B'], $this->recorder->items);
    }

    public function testFailingJobMarkedFailedAfterMaxRetries(): void
    {
        $queue = new JobQueue(
            connection: $this->connection,
            maxRetries: 2,
            baseRetryDelay: 1,
            container: $this->container,
        );

        $id = $queue->push(new FailingJob('boom'));

        $queue->processJobs();
        $this->rewindScheduledAt($id);
        $queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(2, (int) $row['retry_count']);
    }

    public function testStuckJobAtMaxRetriesIsMarkedFailed(): void
    {
        $queue = new JobQueue(
            connection: $this->connection,
            maxRetries: 2,
            lockTimeout: 60,
            container: $this->container,
        );

        $id = $queue->push(new SimpleJob('A'));
        $this->connection->executeStatement(
            "UPDATE jobs SET status = 'in_progress', locked_at = ?, retry_count = 1 WHERE id = ?",
            [(new DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s'), $id]
        );

        $queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(2, (int) $row['retry_count']);
        $this->assertSame([], $this->recorder->items);
    }

    public function testDeleteOnSuccessRemovesCompletedJob(): void
    {
        $queue = new JobQueue(
            connection: $this->connection,
            deleteOnSuccess: true,
            container: $this->container,
        );

        $id = $queue->push(new SimpleJob('A'));
        $queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertFalse($row);
    }

    public function testGetPendingJobsFiltersByType(): void
    {
        $this->queue->push(new SimpleJob('A'), 'email');
        $this->queue->push(new SimpleJob('B'), 'webhook');

        $pending = $this->queue->getPendingJobs(10, 'email');
        $this->assertCount(1, $pending);
        $this->assertSame('email', $pending[0]['type']);
    }

    public function testProcessJobsWithZeroLimitIsNoop(): void
    {
        $this->queue->push(new SimpleJob('A'));
        $this->queue->processJobs(0);

        $this->assertSame([], $this->recorder->items);
    }

    public function testMarkFailedIsNoopForMissingJob(): void
    {
        $this->queue->markFailed(99999, 'ghost');
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM jobs'));
    }

    public function testMarkFailedWithStringErrorSerializesToColumn(): void
    {
        $id = $this->queue->push(new SimpleJob('A'));

        $this->queue->markFailed($id, 'something broke');

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame('something broke', $row['error']);
    }

    public function testFailsJobWithMissingJobClassInPayload(): void
    {
        $id = $this->queue->addJob(['job_data' => ['x' => 1]]);
        $this->queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertStringContainsString('job_class', (string) $row['error']);
    }

    public function testFailsJobWithNonExistentClass(): void
    {
        $id = $this->queue->addJob([
            'job_class' => 'Foo\\Bar\\NotReal',
            'job_data'  => [],
        ]);
        $this->queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertStringContainsString('does not exist', (string) $row['error']);
    }

    public function testFailsJobWhenClassDoesNotImplementJobInterface(): void
    {
        $id = $this->queue->addJob([
            'job_class' => NotAJob::class,
            'job_data'  => [],
        ]);
        $this->queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertStringContainsString('JobInterface', (string) $row['error']);
    }

    public function testProcessFailsJobWhenContainerMissing(): void
    {
        $queue = new JobQueue(connection: $this->connection);
        $id = $queue->push(new SimpleJob('A'));

        $queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertStringContainsString('container', (string) $row['error']);
    }

    public function testProcessFailsJobWithoutFactoryMethod(): void
    {
        $id = $this->queue->push(new JobWithoutFactory());
        $this->queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertStringContainsString('createFromContainer', (string) $row['error']);
    }

    public function testJobDataMissingOrNonArrayDefaultsToEmpty(): void
    {
        // job_data absent entirely
        $id = $this->queue->addJob(['job_class' => SimpleJob::class]);
        $this->queue->processJobs();

        // Should run with empty $data (value => '')
        $this->assertSame([''], $this->recorder->items);
        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame('completed', $row['status']);
    }

    public function testInvalidTableNameRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid table name/');

        new JobQueue(connection: $this->connection, table: 'jobs; DROP TABLE users; --');
    }

    public function testTimestampsAreStoredInUtc(): void
    {
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $queue = new JobQueue(connection: $this->connection, container: $this->container);
            $queue->push(new SimpleJob('A'));

            /** @var string $scheduled */
            $scheduled = $this->connection->fetchOne('SELECT scheduled_at FROM jobs LIMIT 1');

            $stored = new DateTimeImmutable($scheduled . ' UTC');
            $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $this->assertLessThan(5, abs($now->getTimestamp() - $stored->getTimestamp()));
        } finally {
            date_default_timezone_set($previousTz);
        }
    }

    private function rewindScheduledAt(int $id): void
    {
        $this->connection->executeStatement(
            'UPDATE jobs SET scheduled_at = ? WHERE id = ?',
            [(new DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s'), $id]
        );
    }
}
