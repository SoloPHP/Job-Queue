<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
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

    public function testPushInsertsPendingJob(): void
    {
        $id = $this->queue->push(new SimpleJob('A'));

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['retry_count']);
        $this->assertSame(SimpleJob::class, $row['name']);
        $this->assertSame('default', $row['type']);
        $this->assertNull($row['locked_at']);
    }

    public function testProcessRunsJobAndMarksCompleted(): void
    {
        $id = $this->queue->push(new SimpleJob('A'));
        $this->queue->processJobs();

        $this->assertSame(['A'], $this->recorder->items);

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame('completed', $row['status']);
        $this->assertNull($row['locked_at']);
    }

    public function testProcessSkipsFutureScheduledJobs(): void
    {
        $this->queue->push(new SimpleJob('A'), null, new DateTimeImmutable('+1 hour'));
        $this->queue->processJobs();

        $this->assertSame([], $this->recorder->items);
    }

    public function testProcessSkipsExpiredJobs(): void
    {
        $this->queue->push(
            new SimpleJob('A'),
            null,
            new DateTimeImmutable('-2 hours'),
            new DateTimeImmutable('-1 hour'),
        );

        $this->queue->processJobs();

        $this->assertSame([], $this->recorder->items);
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

    public function testProcessOrdersByScheduledAt(): void
    {
        $this->queue->push(new SimpleJob('A'), null, new DateTimeImmutable('-2 seconds'));
        $this->queue->push(new SimpleJob('B'), null, new DateTimeImmutable('-3 seconds'));
        $this->queue->push(new SimpleJob('C'), null, new DateTimeImmutable('-1 second'));

        $this->queue->processJobs();

        $this->assertSame(['B', 'A', 'C'], $this->recorder->items);
    }

    public function testFailingJobIsRetriedWithBackoff(): void
    {
        $id = $this->queue->push(new FailingJob('boom'));
        $before = new DateTimeImmutable();

        $this->queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int) $row['retry_count']);
        $this->assertIsString($row['error']);
        $this->assertStringContainsString('boom', $row['error']);
        $this->assertNull($row['locked_at']);

        $scheduled = new DateTimeImmutable((string) $row['scheduled_at']);
        $this->assertGreaterThan($before, $scheduled);
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

    public function testExponentialBackoffIncreasesDelay(): void
    {
        $queue = new JobQueue(
            connection: $this->connection,
            maxRetries: 10,
            baseRetryDelay: 10,
            container: $this->container,
        );

        $id = $queue->push(new FailingJob('boom'));
        $t0 = time();

        $queue->processJobs();
        $delay1 = $this->scheduledDelaySeconds($id, $t0);

        $this->rewindScheduledAt($id);

        $queue->processJobs();
        $delay2 = $this->scheduledDelaySeconds($id, $t0);

        // retry 1 → 10 * 2^1 = 20 sec; retry 2 → 10 * 2^2 = 40 sec (± test runtime)
        $this->assertEqualsWithDelta(20, $delay1, 2);
        $this->assertEqualsWithDelta(40, $delay2, 2);
    }

    public function testStuckJobIsReclaimed(): void
    {
        $id = $this->queue->push(new SimpleJob('A'));

        // Simulate a worker that locked the job 20 minutes ago but never completed.
        $this->connection->executeStatement(
            "UPDATE jobs SET status = 'in_progress', locked_at = ? WHERE id = ?",
            [(new DateTimeImmutable('-20 minutes'))->format('Y-m-d H:i:s'), $id]
        );

        $this->queue->processJobs();

        $this->assertSame(['A'], $this->recorder->items);
        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame('completed', $row['status']);
        $this->assertSame(1, (int) $row['retry_count']);
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

        // Job was reclaimed once already (retry_count=1) and is now stuck again.
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
        $this->assertSame(['A'], $this->recorder->items);
    }

    public function testGetPendingJobsExcludesInProgress(): void
    {
        $id = $this->queue->push(new SimpleJob('A'));
        $this->queue->push(new SimpleJob('B'));

        $this->connection->executeStatement(
            "UPDATE jobs SET status = 'in_progress', locked_at = ? WHERE id = ?",
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );

        $pending = $this->queue->getPendingJobs();
        $this->assertCount(1, $pending);
        $this->assertSame('B', json_decode((string) $pending[0]['payload'], true)['job_data']['value']);
    }

    public function testAddJobAcceptsRawPayload(): void
    {
        $this->queue->addJob([
            'job_class' => SimpleJob::class,
            'job_data'  => ['value' => 'X'],
        ]);

        $this->queue->processJobs();

        $this->assertSame(['X'], $this->recorder->items);
    }

    public function testProcessFailsJobWhenContainerMissing(): void
    {
        $queue = new JobQueue(connection: $this->connection);
        $id = $queue->push(new SimpleJob('A'));

        $queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame(1, (int) $row['retry_count']);
        $this->assertIsString($row['error']);
        $this->assertStringContainsString('container', $row['error']);
    }

    public function testProcessFailsJobWithoutFactoryMethod(): void
    {
        $id = $this->queue->push(new JobWithoutFactory());

        $this->queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame(1, (int) $row['retry_count']);
        $this->assertIsString($row['error']);
        $this->assertStringContainsString('createFromContainer', $row['error']);
    }

    public function testClaimLocksRowsSoRepeatedCallDoesNotReturnSame(): void
    {
        $this->queue->push(new SimpleJob('A'));
        $this->queue->push(new SimpleJob('B'));

        $first = $this->queue->getPendingJobs();
        $this->assertCount(2, $first);

        // After processing, rows are no longer pending.
        $this->queue->processJobs();
        $second = $this->queue->getPendingJobs();
        $this->assertCount(0, $second);
    }

    public function testMarkFailedIsNoopForMissingJob(): void
    {
        $this->queue->markFailed(99999, 'ghost');
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM jobs'));
    }

    public function testAddJobWithoutJobClassUsesUnknownName(): void
    {
        $id = $this->queue->addJob(['arbitrary' => 'data']);

        $name = (string) $this->connection->fetchOne('SELECT name FROM jobs WHERE id = ?', [$id]);
        $this->assertSame('unknown_job', $name);
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

    public function testFailsJobWithMissingJobClassInPayload(): void
    {
        $id = $this->queue->addJob(['job_data' => ['x' => 1]]);

        $this->queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertIsString($row['error']);
        $this->assertStringContainsString('job_class', $row['error']);
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
        $this->assertIsString($row['error']);
        $this->assertStringContainsString('does not exist', $row['error']);
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
        $this->assertIsString($row['error']);
        $this->assertStringContainsString('JobInterface', $row['error']);
    }

    public function testInstallIsIdempotent(): void
    {
        Schema::install($this->connection);
        Schema::install($this->connection);

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM jobs'));
    }

    private function rewindScheduledAt(int $id): void
    {
        $this->connection->executeStatement(
            'UPDATE jobs SET scheduled_at = ? WHERE id = ?',
            [(new DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s'), $id]
        );
    }

    private function scheduledDelaySeconds(int $id, int $t0): int
    {
        $scheduledAt = (string) $this->connection->fetchOne(
            'SELECT scheduled_at FROM jobs WHERE id = ?',
            [$id]
        );
        return (new DateTimeImmutable($scheduledAt))->getTimestamp() - $t0;
    }
}
