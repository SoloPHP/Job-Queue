<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use Solo\JobQueue\JobQueue;
use Solo\JobQueue\RetryPolicy;
use Solo\JobQueue\Storage\DbalJobStorage;
use Solo\JobQueue\Tests\Fixtures\FailingJob;
use Solo\JobQueue\Tests\Fixtures\JobWithoutFactory;
use Solo\JobQueue\Tests\Fixtures\NotAJob;
use Solo\JobQueue\Tests\Fixtures\RecordingListener;
use Solo\JobQueue\Tests\Fixtures\SimpleJob;

final class JobQueueTest extends QueueTestCase
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
            storage:   $this->storage,
            retry:     new RetryPolicy(maxRetries: 2, baseDelay: 1),
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
            storage:   $this->storage,
            retry:     new RetryPolicy(maxRetries: 2, lockTimeout: 60),
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
            storage: $this->storage,
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
        $queue = new JobQueue(storage: $this->storage);
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

        new DbalJobStorage($this->connection, 'jobs; DROP TABLE users; --');
    }

    public function testPushManyInsertsAllJobsAndReturnsCount(): void
    {
        $count = $this->queue->pushMany(
            [new SimpleJob('A'), new SimpleJob('B'), new SimpleJob('C')],
            'email',
        );

        $this->assertSame(3, $count);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT name, type, payload FROM jobs ORDER BY id'
        );
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('email', $row['type']);
            $this->assertSame(SimpleJob::class, $row['name']);
        }

        $this->queue->processJobs(10, 'email');
        $this->assertSame(['A', 'B', 'C'], $this->recorder->items);
    }

    public function testPushManyOnEmptyArrayIsNoop(): void
    {
        $count = $this->queue->pushMany([]);

        $this->assertSame(0, $count);
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM jobs'));
    }

    public function testPushManyPersistsScheduledAndExpiresAt(): void
    {
        $scheduled = new DateTimeImmutable('2030-01-01 10:00:00', new \DateTimeZone('UTC'));
        $expires   = new DateTimeImmutable('2030-01-02 10:00:00', new \DateTimeZone('UTC'));

        $this->queue->pushMany([new SimpleJob('A'), new SimpleJob('B')], 'later', $scheduled, $expires);

        $rows = $this->connection->fetchAllAssociative('SELECT scheduled_at, expires_at FROM jobs');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('2030-01-01 10:00:00', $row['scheduled_at']);
            $this->assertSame('2030-01-02 10:00:00', $row['expires_at']);
        }
    }

    public function testGetStatsCountsAllStatusesWithZeroDefaults(): void
    {
        $stats = $this->queue->getStats();

        $this->assertSame(
            ['pending' => 0, 'in_progress' => 0, 'completed' => 0, 'failed' => 0],
            $stats
        );
    }

    public function testGetStatsGroupsByStatusAndFiltersByType(): void
    {
        $this->queue->push(new SimpleJob('A'), 'email');
        $this->queue->push(new SimpleJob('B'), 'email');
        $this->queue->push(new SimpleJob('C'), 'webhook');

        $this->queue->processJobs(10, 'email'); // completes both 'email' jobs

        $all = $this->queue->getStats();
        $this->assertSame(2, $all['completed']);
        $this->assertSame(1, $all['pending']);

        $emailOnly = $this->queue->getStats('email');
        $this->assertSame(['pending' => 0, 'in_progress' => 0, 'completed' => 2, 'failed' => 0], $emailOnly);

        $webhookOnly = $this->queue->getStats('webhook');
        $this->assertSame(['pending' => 1, 'in_progress' => 0, 'completed' => 0, 'failed' => 0], $webhookOnly);
    }

    public function testProcessJobsReturnsCountOfRunJobs(): void
    {
        $this->queue->push(new SimpleJob('A'));
        $this->queue->push(new SimpleJob('B'));

        $this->assertSame(2, $this->queue->processJobs(10));
        $this->assertSame(0, $this->queue->processJobs(10));
    }

    public function testListenerReceivesClaimedAndCompletedEvents(): void
    {
        $listener = new RecordingListener();
        $queue = new JobQueue(
            storage: $this->storage,
            container: $this->container,
            listener:  $listener,
        );

        $id = $queue->push(new SimpleJob('A'));
        $queue->processJobs();

        $this->assertSame(
            [
                ['event' => 'claimed', 'id' => $id, 'class' => SimpleJob::class],
                ['event' => 'completed', 'id' => $id],
            ],
            $listener->events
        );
    }

    public function testListenerReceivesFailedEventsWithPermanentFlag(): void
    {
        $listener = new RecordingListener();
        $queue = new JobQueue(
            storage:   $this->storage,
            retry:     new RetryPolicy(maxRetries: 2, baseDelay: 1),
            container: $this->container,
            listener:  $listener,
        );

        $id = $queue->push(new FailingJob('boom'));
        $queue->processJobs();
        $this->rewindScheduledAt($id);
        $queue->processJobs();

        $failed = array_values(array_filter(
            $listener->events,
            static fn(array $e): bool => $e['event'] === 'failed'
        ));

        $this->assertCount(2, $failed);
        $this->assertFalse($failed[0]['permanent']);
        $this->assertTrue($failed[1]['permanent']);
    }

    public function testListenerReceivesReclaimedEvent(): void
    {
        $listener = new RecordingListener();
        $queue = new JobQueue(
            storage:   $this->storage,
            retry:     new RetryPolicy(maxRetries: 3, lockTimeout: 60),
            container: $this->container,
            listener:  $listener,
        );

        $id = $queue->push(new SimpleJob('A'));
        $this->connection->executeStatement(
            "UPDATE jobs SET status = 'in_progress', locked_at = ? WHERE id = ?",
            [(new DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s'), $id]
        );

        $result = $queue->reclaimStuck();

        $this->assertSame(['requeued' => 1, 'failed' => 0], $result);
        $this->assertContains(
            ['event' => 'reclaimed', 'requeued' => 1, 'failed' => 0],
            $listener->events
        );
    }

    public function testAutoReclaimFalseSkipsReclaimInProcessJobs(): void
    {
        $queue = new JobQueue(
            storage:     $this->storage,
            retry:       new RetryPolicy(lockTimeout: 60),
            container:   $this->container,
            autoReclaim: false,
        );

        $id = $queue->push(new SimpleJob('A'));
        $this->connection->executeStatement(
            "UPDATE jobs SET status = 'in_progress', locked_at = ? WHERE id = ?",
            [(new DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s'), $id]
        );

        $queue->processJobs();

        $row = $this->connection->fetchAssociative('SELECT status FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame('in_progress', $row['status']);
    }

    public function testGetFailedJobsReturnsFailedRowsFilteredByType(): void
    {
        $queue = new JobQueue(
            storage:   $this->storage,
            retry:     new RetryPolicy(maxRetries: 1, baseDelay: 1),
            container: $this->container,
        );

        $emailId = $queue->push(new FailingJob('boom'), 'email');
        $hookId  = $queue->push(new FailingJob('boom'), 'webhook');

        $queue->processJobs(10, 'email');
        $queue->processJobs(10, 'webhook');

        $all = $queue->getFailedJobs();
        $this->assertCount(2, $all);

        $emails = $queue->getFailedJobs(50, 'email');
        $this->assertCount(1, $emails);
        $this->assertSame((string) $emailId, (string) $emails[0]['id']);

        $hooks = $queue->getFailedJobs(50, 'webhook');
        $this->assertCount(1, $hooks);
        $this->assertSame((string) $hookId, (string) $hooks[0]['id']);
    }

    public function testRetryResetsFailedJobToPending(): void
    {
        $queue = new JobQueue(
            storage:   $this->storage,
            retry:     new RetryPolicy(maxRetries: 1, baseDelay: 1),
            container: $this->container,
        );

        $id = $queue->push(new FailingJob('boom'));
        $queue->processJobs();

        $this->assertTrue($queue->retry($id));

        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = ?', [$id]);
        $this->assertIsArray($row);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['retry_count']);
        $this->assertNull($row['error']);
    }

    public function testRetryReturnsFalseForMissingOrNonFailedJob(): void
    {
        $this->assertFalse($this->queue->retry(99999));

        $id = $this->queue->push(new SimpleJob('A'));
        $this->assertFalse($this->queue->retry($id)); // status is 'pending', not 'failed'
    }

    public function testClaimDiscardsRowsWithMalformedDriverOutput(): void
    {
        $platform = $this->createStub(SQLitePlatform::class);
        $conn = $this->createStub(Connection::class);
        $conn->method('getDatabasePlatform')->willReturn($platform);
        $conn->method('transactional')->willReturnCallback(
            static fn(Closure $f): mixed => $f($conn)
        );
        $conn->method('fetchFirstColumn')->willReturn([1]);
        $conn->method('executeStatement')->willReturn(1);
        $conn->method('fetchAllAssociative')->willReturn([
            ['id' => 1, 'name' => null, 'payload' => 'x'],
        ]);

        $queue = new JobQueue(storage: new DbalJobStorage($conn), autoReclaim: false);

        $this->assertSame(0, $queue->processJobs(1));
    }

    public function testTimestampsAreStoredInUtc(): void
    {
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $queue = new JobQueue(storage: new DbalJobStorage($this->connection), container: $this->container);
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
