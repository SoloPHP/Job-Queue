<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Solo\Contracts\JobQueue\JobInterface;
use Solo\Contracts\JobQueue\JobQueueListener;
use Solo\JobQueue\{ClaimedJob, JobQueue, JobStatus, RetryPolicy};
use Solo\JobQueue\Codec\JobCodecInterface;
use Solo\JobQueue\Storage\JobStorageInterface;
use Solo\JobQueue\Tests\Fixtures\{FailingJob, SimpleJob, UnstorableErrorJob};
use Solo\JobQueue\Tests\Support\{CallbackCodec, DatabaseTestCase, FrozenClock, RecordingListener};
use Throwable;

final class JobQueueTest extends DatabaseTestCase
{
    private FrozenClock $clock;
    private JobQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock();
        $this->queue = $this->queue();
    }

    private function queue(
        ?RetryPolicy $retry = null,
        ?JobQueueListener $listener = null,
        bool $delete = false,
    ): JobQueue {
        return new JobQueue(
            $this->storage,
            $this->codec(),
            retry: $retry,
            deleteOnSuccess: $delete,
            listener: $listener,
            clock: $this->clock,
        );
    }

    public function testTypeFilteringAndCounts(): void
    {
        $this->queue->push(new SimpleJob('email'), 'email');
        $this->queue->push(new SimpleJob('webhook'), 'webhook');
        $this->assertCount(1, $this->queue->getPendingJobs(10, 'email'));
        $this->assertSame(1, $this->queue->processJobs(10, 'email'));
        $this->assertSame(['email'], $this->recorder->items);
        $this->assertSame(
            ['pending' => 1, 'in_progress' => 0, 'completed' => 1, 'failed' => 0, 'expired' => 0],
            $this->queue->getStats(),
        );
        $this->assertSame(1, $this->queue->processJobs(10, 'webhook'));
        $this->assertSame(['email', 'webhook'], $this->recorder->items);
    }

    public function testInspectionReturnsTypedRecords(): void
    {
        $expiresAt = $this->clock->now()->modify('+1 hour');
        $id = $this->queue->push(new SimpleJob('A'), 'email', expiresAt: $expiresAt);
        [$record] = $this->queue->getPendingJobs();
        $this->assertSame($id, $record->id);
        $this->assertSame(SimpleJob::class, $record->name);
        $this->assertSame('email', $record->type);
        $this->assertSame(JobStatus::Pending, $record->status);
        $this->assertSame([0, 3], [$record->attempts, $record->maxAttempts]);
        $this->assertNull($record->error);
        $this->assertNull($record->finishedAt);
        $this->assertEquals($this->clock->now(), $record->scheduledAt);
        $this->assertEquals($this->clock->now(), $record->createdAt);
        $this->assertEquals($expiresAt, $record->expiresAt);
        $this->assertEquals(
            ['job_class' => SimpleJob::class, 'job_data' => ['value' => 'A']],
            json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testPolicyIsPersistedAndFirstRetryUsesBaseDelay(): void
    {
        $producer = $this->queue(new RetryPolicy(maxAttempts: 2, baseDelay: 10));
        $id = $producer->push(new FailingJob('boom'));
        $consumer = $this->queue(new RetryPolicy(maxAttempts: 99, baseDelay: 999));
        $this->assertSame(1, $consumer->processJobs(1));
        $this->assertSame(0, $consumer->processJobs(1));
        $this->clock->advance(10);
        $this->assertSame(1, $consumer->processJobs(1));
        $record = $consumer->getFailedJobs()[0];
        $this->assertSame($id, $record->id);
        $this->assertSame(2, $record->attempts);
    }

    public function testFailedInspectionAndManualRetry(): void
    {
        $queue = $this->queue(new RetryPolicy(maxAttempts: 1));
        $id = $queue->push(new FailingJob('boom'), 'email');
        $queue->processJobs(1);
        $this->assertCount(1, $queue->getFailedJobs(50, 'email'));
        $this->assertSame([], $queue->getFailedJobs(50, 'other'));
        $this->assertTrue($queue->retry($id));
        $this->assertSame(0, $queue->getPendingJobs()[0]->attempts);
        $this->assertFalse($queue->retry($id));
        $this->assertFalse($queue->retry(999999));
    }

    public function testExpiredJobsBecomeTerminalAndNeedNewDeadlineToRetry(): void
    {
        $id = $this->queue->push(new SimpleJob('expired'), expiresAt: $this->clock->now());
        $this->assertSame(0, $this->queue->processJobs());
        $this->assertSame(1, $this->queue->getStats()['expired']);
        $this->assertSame(0, $this->queue->getStats()['pending']);
        $this->assertFalse($this->queue->retry($id));
        $this->assertFalse($this->queue->retry($id, $this->clock->now()));
        $this->assertTrue($this->queue->retry($id, $this->clock->now()->modify('+1 hour')));
        $this->assertSame(1, $this->queue->processJobs());
    }

    public function testExpiredFailedJobCannotBecomeUnreachablePending(): void
    {
        $queue = $this->queue(new RetryPolicy(maxAttempts: 1));
        $id = $queue->push(new FailingJob('boom'), expiresAt: $this->clock->now()->modify('+1 minute'));
        $queue->processJobs(1);
        $this->clock->advance(60);
        $this->assertFalse($queue->retry($id));
        $this->assertSame(0, $queue->getStats()['pending']);
        $this->assertTrue($queue->retry($id, $this->clock->now()->modify('+1 hour')));
    }

    public function testRetryThatWouldMissTheDeadlineExpiresImmediately(): void
    {
        $listener = new RecordingListener();
        $queue = $this->queue(new RetryPolicy(maxAttempts: 3, baseDelay: 60), $listener);
        $id = $queue->push(new FailingJob('boom'), expiresAt: $this->clock->now()->modify('+30 seconds'));
        $this->assertSame(1, $queue->processJobs(1));
        $this->assertSame(1, $queue->getStats()['expired']);
        $this->assertSame([
            ['event' => 'claimed', 'id' => $id, 'class' => FailingJob::class],
            ['event' => 'failed', 'id' => $id, 'permanent' => true, 'error' => RuntimeException::class],
        ], $listener->events);
    }

    public function testDeleteOnSuccessAndListenerEvents(): void
    {
        $listener = new RecordingListener();
        $queue = $this->queue(listener: $listener, delete: true);
        $id = $queue->push(new SimpleJob('A'));
        $queue->processJobs();
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM jobs'));
        $this->assertSame([
            ['event' => 'claimed', 'id' => $id, 'class' => SimpleJob::class],
            ['event' => 'completed', 'id' => $id],
        ], $listener->events);
    }

    public function testObserverFailureNeverRetriesSuccessfulJob(): void
    {
        $listener = new class implements JobQueueListener {
            public function onClaimed(int $jobId, string $jobClass): void
            {
            }
            public function onCompleted(int $jobId): void
            {
                throw new RuntimeException('observer failed');
            }
            public function onFailed(int $jobId, Throwable|string $error, bool $permanent): void
            {
                throw new RuntimeException('must not fail');
            }
            public function onReclaimed(int $requeuedCount, int $permanentlyFailedCount): void
            {
            }
        };
        $queue = $this->queue(new RetryPolicy(baseDelay: 0), $listener);
        $queue->push(new SimpleJob('once'));
        try {
            $queue->processJobs();
            $this->fail('Observer error must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('observer failed', $e->getMessage());
        }
        $this->assertSame(1, $queue->getStats()['completed']);
        $this->assertSame(0, $queue->processJobs());
        $this->assertSame(['once'], $this->recorder->items);
    }

    public function testCompletionPersistenceFailureDoesNotCallFail(): void
    {
        $storage = $this->createMock(JobStorageInterface::class);
        $leaseUntil = $this->clock->now()->modify('+600 seconds');
        $payload = $this->codec()->encode(new SimpleJob('once'));
        $storage->method('claim')->willReturn(
            new ClaimedJob(1, SimpleJob::class, $payload, 1, 'token', $leaseUntil, new RetryPolicy()),
        );
        $storage->method('complete')->willThrowException(new RuntimeException('db unavailable'));
        $storage->expects($this->never())->method('fail');
        $queue = new JobQueue($storage, $this->codec(), clock: $this->clock);
        $this->expectExceptionMessage('db unavailable');
        $queue->processNext();
    }

    public function testMaintenanceReclaimsWithEachJobsOwnLeaseAndPolicy(): void
    {
        $long = new JobQueue(
            $this->storage,
            $this->codec(),
            retry: new RetryPolicy(maxAttempts: 2, baseDelay: 7),
            leaseDuration: 1800,
            clock: $this->clock,
        );
        $short = new JobQueue(
            $this->storage,
            $this->codec(),
            retry: new RetryPolicy(maxAttempts: 1),
            leaseDuration: 120,
            clock: $this->clock,
        );
        $long->push(new SimpleJob('A'), 'long');
        $claim = $long->claim('long');
        $this->assertNotNull($claim);
        $this->clock->advance(300);
        $this->assertSame(['requeued' => 0, 'failed' => 0, 'expired' => 0], $short->maintain());
        $this->clock->advance(1500);
        $this->assertSame(['requeued' => 1, 'failed' => 0, 'expired' => 0], $short->maintain());
        $this->assertFalse($long->complete($claim));
        $beforeBackoff = $long->claim('long');
        $this->assertNull($beforeBackoff);
        $this->clock->advance(7);
        $second = $long->claim('long');
        $this->assertNotNull($second);
        $this->assertSame(2, $second->attempt);
        $this->clock->advance(1800);
        $this->assertSame(['requeued' => 0, 'failed' => 1, 'expired' => 0], $short->maintain());
    }

    public function testMaintenanceExpiresOverdueJobsAndNotifiesTheListener(): void
    {
        $listener = new RecordingListener();
        $queue = $this->queue(listener: $listener);
        $queue->push(new SimpleJob('stuck'), expiresAt: $this->clock->now()->modify('+700 seconds'));
        $this->assertNotNull($queue->claim());
        $queue->push(new SimpleJob('late'), expiresAt: $this->clock->now()->modify('+60 seconds'));
        $this->clock->advance(800);
        $this->assertSame(['requeued' => 0, 'failed' => 0, 'expired' => 2], $queue->maintain());
        $this->assertSame([['event' => 'reclaimed', 'requeued' => 0, 'failed' => 1]], $listener->events);
        $this->assertSame(2, $queue->getStats()['expired']);
    }

    public function testProcessNextDoesNotRunMaintenance(): void
    {
        $this->queue->push(new SimpleJob('A'));
        $this->assertNotNull($this->queue->claim());
        $this->clock->advance(601);
        $this->assertFalse($this->queue->processNext());
        $this->assertSame(1, $this->queue->getStats()['in_progress']);
        $this->assertSame(0, $this->queue->processJobs());
        $this->assertSame(1, $this->queue->getStats()['pending']);
    }

    public function testEachJobIsClaimedImmediatelyBeforeExecution(): void
    {
        $observed = [];
        $codec = new CallbackCodec(function () use (&$observed): void {
            $observed[] = $this->storage->countByStatus(null);
            $this->clock->advance(6);
        });
        $queue = new JobQueue($this->storage, $codec, leaseDuration: 10, clock: $this->clock);
        $queue->pushMany([new SimpleJob('1'), new SimpleJob('2'), new SimpleJob('3')]);
        $this->assertSame(3, $queue->processJobs(3));
        $this->assertSame([2, 1, 0], array_column($observed, 'pending'));
        $this->assertSame([1, 1, 1], array_column($observed, 'in_progress'));
        $this->assertSame(3, $queue->getStats()['completed']);
    }

    public function testOuterTransactionIsRejectedBeforeAnyQueueMutation(): void
    {
        $this->queue->push(new SimpleJob('A'));
        $this->connection->beginTransaction();
        try {
            $this->queue->processJobs();
            $this->fail('Expected consumer transaction guard');
        } catch (RuntimeException $e) {
            $this->assertSame([], $this->recorder->items);
            $this->assertSame(1, $this->queue->getStats()['pending']);
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testRenewReturnsTheExtendedClaimButCannotReviveAnEndedLease(): void
    {
        $this->queue->push(new SimpleJob('A'));
        $claim = $this->queue->claim();
        $this->assertNotNull($claim);
        $this->assertEquals($this->clock->now()->modify('+600 seconds'), $claim->leaseUntil);
        $this->clock->advance(500);
        $renewed = $this->queue->renew($claim);
        $this->assertNotNull($renewed);
        $this->assertSame($claim->token, $renewed->token);
        $this->assertEquals($this->clock->now()->modify('+600 seconds'), $renewed->leaseUntil);
        $this->clock->advance(200);
        $this->assertTrue($this->queue->complete($claim));
        $this->assertNull($this->queue->renew($renewed));

        $this->queue->push(new SimpleJob('B'));
        $claim = $this->queue->claim();
        $this->assertNotNull($claim);
        $this->clock->advance(600);
        $this->assertNull($this->queue->renew($claim));
    }

    public function testLateAcknowledgementIsAcceptedUntilMaintenanceReclaimsTheJob(): void
    {
        $this->queue->push(new SimpleJob('A'));
        $claim = $this->queue->claim();
        $this->assertNotNull($claim);
        $this->clock->advance(900);
        $this->assertTrue($this->queue->complete($claim));
        $this->assertSame(1, $this->queue->getStats()['completed']);

        $this->queue->push(new SimpleJob('B'));
        $claim = $this->queue->claim();
        $this->assertNotNull($claim);
        $this->clock->advance(900);
        $this->assertSame(1, $this->queue->maintain()['requeued']);
        $this->assertFalse($this->queue->complete($claim));
        $this->assertFalse($this->queue->fail($claim, 'too late'));
    }

    public function testScheduleAndExpiryAreConvertedToUtc(): void
    {
        $this->queue->pushMany(
            [new SimpleJob('A')],
            'later',
            new DateTimeImmutable('2030-01-01T03:00:00+03:00'),
            new DateTimeImmutable('2030-01-01T04:00:00+03:00'),
        );
        $record = $this->queue->getPendingJobs()[0];
        $this->assertSame('2030-01-01 00:00:00 UTC', $record->scheduledAt->format('Y-m-d H:i:s T'));
        $this->assertSame('2030-01-01 01:00:00 UTC', $record->expiresAt?->format('Y-m-d H:i:s T'));
        $this->assertSame(0, $this->queue->pushMany([]));
    }

    public function testZeroLimitDoesNothingAndNegativeLimitIsRejected(): void
    {
        $this->queue->push(new SimpleJob('A'));
        $this->assertSame(0, $this->queue->processJobs(0));
        $this->assertSame(1, $this->queue->getStats()['pending']);
        $this->expectException(InvalidArgumentException::class);
        $this->queue->processJobs(-1);
    }

    public function testFailureAfterDeadlineIsReportedAsTerminal(): void
    {
        $listener = new RecordingListener();
        $queue = $this->queue(listener: $listener);
        $id = $queue->push(new SimpleJob('A'), expiresAt: $this->clock->now()->modify('+5 seconds'));
        $claim = $queue->claim();
        $this->assertNotNull($claim);
        $this->clock->advance(6);
        $this->assertTrue($queue->fail($claim, 'too late'));
        $this->assertSame(1, $queue->getStats()['expired']);
        $this->assertSame(
            [['event' => 'failed', 'id' => $id, 'permanent' => true, 'error' => 'too late']],
            $listener->events,
        );
    }

    public function testSlowObserverCannotStartHandlerAfterTheLeaseEnded(): void
    {
        $events = new RecordingListener();
        $listener = new class ($this->clock, $events) implements JobQueueListener {
            public function __construct(private FrozenClock $clock, private RecordingListener $events)
            {
            }
            public function onClaimed(int $jobId, string $jobClass): void
            {
                $this->events->onClaimed($jobId, $jobClass);
                $this->clock->advance(600);
            }
            public function onCompleted(int $jobId): void
            {
                $this->events->onCompleted($jobId);
            }
            public function onFailed(int $jobId, Throwable|string $error, bool $permanent): void
            {
                $this->events->onFailed($jobId, $error, $permanent);
            }
            public function onReclaimed(int $requeuedCount, int $permanentlyFailedCount): void
            {
                $this->events->onReclaimed($requeuedCount, $permanentlyFailedCount);
            }
        };
        $queue = $this->queue(listener: $listener);
        $id = $queue->push(new SimpleJob('must not run'));
        $this->assertTrue($queue->processNext());
        $this->assertSame([], $this->recorder->items);
        $this->assertSame(1, $queue->getStats()['pending']);
        $this->assertSame([
            ['event' => 'claimed', 'id' => $id, 'class' => SimpleJob::class],
            [
                'event' => 'failed',
                'id' => $id,
                'permanent' => false,
                'error' => 'Lease expired before execution started',
            ],
        ], $events->events);
    }

    public function testCustomCodecCannotPersistNonJsonPayloadOnAnyDatabase(): void
    {
        $codec = new class implements JobCodecInterface {
            public function encode(JobInterface $job): string
            {
                return 'not JSON';
            }

            public function decode(string $payload): JobInterface
            {
                throw new RuntimeException('Unused');
            }
        };
        $queue = new JobQueue($this->storage, $codec);
        foreach ([false, true] as $bulk) {
            try {
                if ($bulk) {
                    $queue->pushMany([new SimpleJob('A')]);
                } else {
                    $queue->push(new SimpleJob('A'));
                }
                $this->fail('Expected invalid codec payload to be rejected before insertion');
            } catch (InvalidArgumentException $error) {
                $this->assertSame('JobCodecInterface::encode() must return valid JSON.', $error->getMessage());
            }
        }
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM jobs'));
    }

    public function testUnstorableErrorTextIsSanitizedBeforeSaving(): void
    {
        $id = $this->queue->push(new UnstorableErrorJob());
        $this->assertSame(1, $this->queue->processJobs(1));
        $this->assertSame(1, $this->queue->getStats()['pending']);
        $error = $this->connection->fetchOne('SELECT error FROM jobs WHERE id = ?', [$id]);
        $this->assertIsString($error);
        $this->assertTrue(mb_check_encoding($error, 'UTF-8'));
        $this->assertStringNotContainsString("\0", $error);
        $this->assertSame(16384, strlen($error));
        $this->assertStringStartsWith(RuntimeException::class . ': upstream said ??\0xxx', $error);
        $this->assertStringEndsWith('...', $error);
    }

    public function testPruneDeletesFinishedJobsOlderThanTheCutoff(): void
    {
        $queue = $this->queue(new RetryPolicy(maxAttempts: 1));
        $queue->push(new SimpleJob('old'));
        $queue->push(new FailingJob('boom'));
        $this->assertSame(2, $queue->processJobs());
        $this->clock->advance(3600);
        $recent = $queue->push(new SimpleJob('recent'));
        $this->assertSame(1, $queue->processJobs());
        $waiting = $queue->push(new SimpleJob('waiting'), scheduledAt: $this->clock->now()->modify('+1 day'));
        $cutoff = $this->clock->now()->modify('-1 minute');

        $this->assertSame(1, $queue->prune($cutoff));
        $this->assertSame(1, $queue->prune($cutoff, [JobStatus::Failed, JobStatus::Expired]));
        $this->assertSame(0, $queue->prune($cutoff));
        $this->assertSame(
            [$recent, $waiting],
            array_map(intval(...), $this->connection->fetchFirstColumn('SELECT id FROM jobs ORDER BY id')),
        );
    }

    public function testPruneRejectsUnfinishedStatuses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->queue->prune($this->clock->now(), [JobStatus::Pending]);
    }

    public function testInvalidLeaseRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JobQueue($this->storage, $this->codec(), leaseDuration: 0);
    }
}
