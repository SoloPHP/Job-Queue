<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use InvalidArgumentException;
use RuntimeException;
use Solo\JobQueue\ClaimedJob;
use Solo\JobQueue\JobStatus;
use Solo\JobQueue\RetryPolicy;
use Solo\JobQueue\Storage\DbalJobStorage;

final class StorageTest extends QueueTestCase
{
    public function testClaimStartsOneAttemptAndPersistsTheProducerPolicy(): void
    {
        $policy = new RetryPolicy(maxAttempts: 7, baseDelay: 12, maxDelay: 48);
        $first = $this->insert('email', $policy);
        $second = $this->insert('email', $policy);
        $this->insert('other');

        $job = $this->claim('email');
        self::assertSame($first, $job->id);
        self::assertSame(1, $job->attempt);
        self::assertSame(7, $job->retryPolicy->maxAttempts);
        self::assertSame(12, $job->retryPolicy->baseDelay);
        self::assertSame(48, $job->retryPolicy->maxDelay);
        self::assertEquals(self::at(60), $job->leaseUntil);
        self::assertNotSame('', $job->token);
        self::assertSame($second, $this->claim('email')->id);
        self::assertNull($this->storage->claim('email', self::at(0), self::at(60)));
    }

    public function testStaleAttemptCannotAlterANewerAttemptOrCompletedJob(): void
    {
        $id = $this->insert('email', new RetryPolicy(maxAttempts: 3, baseDelay: 1));
        $old = $this->claim('email', self::at(10));
        [$expired] = $this->storage->expiredLeases(self::at(10), 10);
        self::assertTrue($this->storage->reclaim($expired, JobStatus::Pending, 'timeout', self::at(10), self::at(11)));
        $new = $this->claim('email', self::at(60), self::at(11));
        self::assertSame(2, $new->attempt);
        self::assertNotSame($old->token, $new->token);
        self::assertFalse($this->storage->complete($old, self::at(12), false));
        self::assertFalse($this->storage->complete($old, self::at(12), true));
        self::assertFalse($this->storage->fail($old, JobStatus::Pending, 'late', self::at(12), self::at(13)));
        self::assertTrue($this->storage->complete($new, self::at(12), false));
        self::assertFalse($this->storage->fail($new, JobStatus::Failed, 'late', self::at(13), null));
        self::assertSame('completed', $this->jobStatus($id));
    }

    public function testAttemptCanBeAcknowledgedAfterItsLeaseUntilItIsReclaimed(): void
    {
        $this->insert('email');
        self::assertTrue($this->storage->complete($this->claim('email', self::at(10)), self::at(30), false));
        $this->insert('email');
        $job = $this->claim('email', self::at(10));
        self::assertTrue($this->storage->fail($job, JobStatus::Failed, 'late', self::at(30), null));
    }

    public function testFailureReschedulesOrFinishesExactlyAsInstructed(): void
    {
        $id = $this->insert('email', new RetryPolicy(maxAttempts: 2, baseDelay: 10));
        $first = $this->claim('email');
        self::assertTrue($this->storage->fail($first, JobStatus::Pending, 'temporary', self::at(1), self::at(11)));
        self::assertNull($this->storage->claim('email', self::at(10), self::at(60)));
        $second = $this->claim('email', self::at(60), self::at(11));
        self::assertSame(2, $second->attempt);
        self::assertTrue($this->storage->fail($second, JobStatus::Failed, 'again', self::at(12), null));

        $record = $this->storage->failed(10, 'email')[0];
        self::assertSame($id, $record->id);
        self::assertSame(JobStatus::Failed, $record->status);
        self::assertSame('again', $record->error);
        self::assertEquals(self::at(12), $record->finishedAt);

        self::assertTrue($this->storage->retry($id, self::at(13), null));
        $retried = $this->claim('email', self::at(60), self::at(13));
        self::assertSame(1, $retried->attempt);
        self::assertSame(2, $retried->retryPolicy->maxAttempts);
    }

    public function testReleaseRejectsTransitionsThatAreNotAFailureOutcome(): void
    {
        $this->insert('email');
        $job = $this->claim('email');
        $invalid = [
            [JobStatus::Completed, null],
            [JobStatus::InProgress, null],
            [JobStatus::Pending, null],
            [JobStatus::Failed, self::at(5)],
        ];
        foreach ($invalid as [$status, $runAt]) {
            try {
                $this->storage->fail($job, $status, 'boom', self::at(1), $runAt);
                self::fail("Releasing an attempt as {$status->value} must be rejected");
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }
        self::assertSame('in_progress', $this->jobStatus($job->id));
    }

    public function testReclaimOnlyTakesTheExactExpiredLease(): void
    {
        $this->insert('email', new RetryPolicy(baseDelay: 1));
        $job = $this->claim('email', self::at(10));
        self::assertSame([], $this->storage->expiredLeases(self::at(9), 10));
        [$expired] = $this->storage->expiredLeases(self::at(10), 10);
        self::assertSame($job->token, $expired->token);
        self::assertEquals(self::at(10), $expired->leaseUntil);
        self::assertFalse($this->storage->reclaim($expired, JobStatus::Pending, 'timeout', self::at(9), self::at(11)));
        // A renewal after the lease was read wins over the reclaim.
        self::assertTrue($this->storage->renew($job, self::at(5), self::at(30)));
        self::assertFalse($this->storage->reclaim($expired, JobStatus::Pending, 'timeout', self::at(10), self::at(11)));
        self::assertSame('in_progress', $this->jobStatus($job->id));
    }

    public function testRenewalIsIdempotentAndCannotShortenOrReviveTheLease(): void
    {
        $this->insert('email', new RetryPolicy(baseDelay: 1));
        $job = $this->claim('email', self::at(10));
        self::assertTrue($this->storage->renew($job, self::at(5), self::at(20)));
        self::assertTrue($this->storage->renew($job, self::at(5), self::at(20)));
        self::assertTrue($this->storage->renew($job, self::at(5), self::at(15)));
        self::assertSame([], $this->storage->expiredLeases(self::at(19), 10));
        self::assertFalse($this->storage->renew($job, self::at(20), self::at(30)));
        self::assertCount(1, $this->storage->expiredLeases(self::at(20), 10));
    }

    public function testExpiryFinishesOverduePendingJobsAndRetryNeedsANewDeadline(): void
    {
        $deadline = self::at(0);
        $id = $this->storage->insert('TestJob', 'email', '{}', self::at(0), $deadline, new RetryPolicy(), self::at(0));
        self::assertSame(1, $this->storage->expirePending(self::at(0), 'deadline'));
        self::assertSame('expired', $this->jobStatus($id));
        self::assertSame(1, $this->storage->countByStatus('email')['expired']);
        self::assertFalse($this->storage->retry($id, self::at(0), null));
        self::assertTrue($this->storage->retry($id, self::at(0), self::at(120)));
        $job = $this->claim('email');
        self::assertFalse($this->storage->retry($id, self::at(0), self::at(180)));
        self::assertTrue($this->storage->complete($job, self::at(1), false));
        self::assertFalse($this->storage->retry($id, self::at(0), self::at(180)));
        self::assertSame('completed', $this->jobStatus($id));
    }

    public function testFinishTimeIsRecordedForFinishedJobsAndClearedOnRetry(): void
    {
        $id = $this->insert('email', new RetryPolicy(maxAttempts: 1));
        self::assertTrue($this->storage->fail($this->claim('email'), JobStatus::Failed, 'boom', self::at(5), null));
        self::assertEquals(self::at(5), $this->storage->failed(1, null)[0]->finishedAt);
        self::assertTrue($this->storage->retry($id, self::at(6), null));
        $record = $this->storage->pending(1, null, self::at(6))[0];
        self::assertNull($record->finishedAt);
        self::assertNull($record->error);
        self::assertSame(0, $record->attempts);
        $retried = $this->claim('email', self::at(60), self::at(6));
        self::assertTrue($this->storage->complete($retried, self::at(7), false));
        self::assertSame(
            '2030-01-01 00:00:07',
            $this->connection->fetchOne('SELECT finished_at FROM jobs WHERE id = ?', [$id]),
        );
    }

    public function testPruneDeletesOnlyMatchingFinishedJobs(): void
    {
        $old = $this->insert('email', new RetryPolicy(maxAttempts: 1));
        self::assertTrue($this->storage->complete($this->claim('email'), self::at(10), false));
        $failed = $this->insert('email', new RetryPolicy(maxAttempts: 1));
        self::assertTrue($this->storage->fail($this->claim('email'), JobStatus::Failed, 'boom', self::at(10), null));
        $recent = $this->insert('email');
        self::assertTrue($this->storage->complete($this->claim('email'), self::at(100), false));
        $pending = $this->insert('email');

        self::assertSame(1, $this->storage->prune(self::at(50), [JobStatus::Completed]));
        self::assertSame([$failed, $recent, $pending], $this->ids());
        self::assertSame(1, $this->storage->prune(self::at(50), [JobStatus::Failed, JobStatus::Expired]));
        self::assertSame([$recent, $pending], $this->ids());
        self::assertNotContains($old, $this->ids());
    }

    public function testPruneDeletesLargeHistoriesInBatches(): void
    {
        $this->storage->insertMany(
            array_fill(0, 1500, ['TestJob', '{}']),
            'bulk',
            self::at(0),
            null,
            new RetryPolicy(),
            self::at(0),
        );
        $this->connection->executeStatement(
            "UPDATE jobs SET status = 'completed', finished_at = ?",
            ['2029-12-31 00:00:00'],
        );
        self::assertSame(1500, $this->storage->prune(self::at(0), [JobStatus::Completed]));
        self::assertSame([], $this->ids());
    }

    public function testCompletionCanDeleteOnlyTheOwnedAttempt(): void
    {
        $id = $this->insert('email');
        self::assertTrue($this->storage->complete($this->claim('email'), self::at(0), true));
        self::assertFalse($this->connection->fetchOne('SELECT id FROM jobs WHERE id = ?', [$id]));
    }

    public function testClaimRejectsAnImmediatelyExpiredLease(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->storage->claim(null, self::at(0), self::at(0));
    }

    public function testProducerCanJoinATransactionButConsumerCannot(): void
    {
        $this->connection->beginTransaction();
        try {
            $this->insert('email');
            $this->expectException(RuntimeException::class);
            $this->storage->claim(null, self::at(0), self::at(60));
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testConsumerRejectsDisabledAutocommit(): void
    {
        $this->connection->setAutoCommit(false);
        try {
            $this->expectException(RuntimeException::class);
            $this->storage->expiredLeases(self::at(0), 10);
        } finally {
            $this->connection->setAutoCommit(true);
        }
    }

    public function testConstructorDoesNotTouchTheConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('getDatabasePlatform');
        new DbalJobStorage($connection);
    }

    public function testUnsupportedStoragePlatformIsRejectedOnFirstUse(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($this->createStub(AbstractPlatform::class));
        $storage = new DbalJobStorage($connection);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported database platform');
        $storage->countByStatus(null);
    }

    private static function at(int $seconds): DateTimeImmutable
    {
        return (new DateTimeImmutable('2030-01-01 00:00:00 UTC'))->modify("+{$seconds} seconds");
    }

    private function insert(string $type, ?RetryPolicy $policy = null): int
    {
        $policy ??= new RetryPolicy();
        return $this->storage->insert('TestJob', $type, '{}', self::at(0), null, $policy, self::at(0));
    }

    private function claim(
        string $type,
        ?DateTimeImmutable $leaseUntil = null,
        ?DateTimeImmutable $now = null,
    ): ClaimedJob {
        $job = $this->storage->claim($type, $now ?? self::at(0), $leaseUntil ?? self::at(60));
        self::assertNotNull($job);
        return $job;
    }

    private function jobStatus(int $id): mixed
    {
        return $this->connection->fetchOne('SELECT status FROM jobs WHERE id = ?', [$id]);
    }

    /** @return list<int> */
    private function ids(): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn('SELECT id FROM jobs ORDER BY id'));
    }
}
