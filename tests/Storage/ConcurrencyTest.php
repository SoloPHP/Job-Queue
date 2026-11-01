<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Storage;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use RuntimeException;
use Solo\JobQueue\RetryPolicy;
use Solo\JobQueue\Storage\DbalJobStorage;
use Solo\JobQueue\Tests\Support\DatabaseTestCase;
use Throwable;

/** Exercises real independent database connections, including SQLite file locks. */
final class ConcurrencyTest extends DatabaseTestCase
{
    private DateTimeImmutable $now;
    private DateTimeImmutable $leaseUntil;
    private Connection $otherConnection;
    private DbalJobStorage $otherStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSqliteFile();
        $this->now = new DateTimeImmutable('2030-01-01 00:00:00 UTC');
        $this->leaseUntil = $this->now->modify('+1 minute');
        $this->otherConnection = DriverManager::getConnection($this->connection->getParams());
        if ($this->isSqlite()) {
            $this->otherConnection->executeStatement('PRAGMA busy_timeout = 2000');
        }
        $this->otherStorage = new DbalJobStorage($this->otherConnection);
    }

    protected function tearDown(): void
    {
        $this->otherConnection->close();
        parent::tearDown();
    }

    public function testTwoConnectionsCannotClaimTheSameAttempt(): void
    {
        $firstId = $this->insertJob();
        $secondId = $this->insertJob();

        $first = $this->storage->claim(null, $this->now, $this->leaseUntil);
        $second = $this->otherStorage->claim(null, $this->now, $this->leaseUntil);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($firstId, $first->id);
        $this->assertSame($secondId, $second->id);
        $this->assertNotSame($first->token, $second->token);
        $this->assertNull($this->storage->claim(null, $this->now, $this->leaseUntil));
        $this->assertTrue($this->storage->complete($first, $this->now, false));
        $this->assertTrue($this->otherStorage->complete($second, $this->now, false));
    }

    public function testClaimSkipsRowLockedByAnotherConnection(): void
    {
        if ($this->isSqlite()) {
            $this->markTestSkipped('SQLite uses a database write lock; tested in the fork contention test');
        }

        $lockedId = $this->insertJob();
        $availableId = $this->insertJob();
        $this->connection->beginTransaction();
        try {
            $this->connection->fetchOne('SELECT id FROM jobs WHERE id = ? FOR UPDATE', [$lockedId]);
            $claim = $this->otherStorage->claim(null, $this->now, $this->leaseUntil);
            $this->assertNotNull($claim);
            $this->assertSame($availableId, $claim->id);
        } finally {
            $this->connection->rollBack();
        }

        $previouslyLocked = $this->storage->claim(null, $this->now, $this->leaseUntil);
        $this->assertNotNull($previouslyLocked);
        $this->assertSame($lockedId, $previouslyLocked->id);
    }

    public function testSqliteClaimWaitsForConcurrentWriterWithoutLosingTheJob(): void
    {
        if (!$this->isSqlite()) {
            $this->markTestSkipped('SQLite-specific write contention');
        }
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The contention test requires pcntl and Unix socket pairs');
        }

        $id = $this->insertJob();
        $params = $this->connection->getParams();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        // Never inherit live PDO handles across fork: child destruction of an
        // inherited SQLite transaction can remove the parent's journal file.
        $this->connection->close();
        $this->otherConnection->close();

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($sockets[0]);
            fclose($sockets[1]);
            $this->fail('Could not fork the SQLite claimant');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            try {
                $connection = DriverManager::getConnection($params);
                $connection->executeStatement('PRAGMA busy_timeout = 2000');
                fwrite($sockets[1], "ready\n");
                if (fgets($sockets[1]) !== "go\n") {
                    throw new RuntimeException('Parent did not start the contention test');
                }
                $claim = (new DbalJobStorage($connection))->claim(null, $this->now, $this->leaseUntil);
                fwrite($sockets[1], json_encode(['id' => $claim?->id], JSON_THROW_ON_ERROR) . "\n");
                $connection->close();
                fclose($sockets[1]);
                exit(0);
            } catch (Throwable $error) {
                fwrite($sockets[1], json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR) . "\n");
                exit(1);
            }
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        $waited = false;
        try {
            $this->assertSame("ready\n", fgets($sockets[0]));
            $this->connection->beginTransaction();
            $this->connection->executeStatement('UPDATE jobs SET error = ? WHERE id = ?', ['writer holds lock', $id]);
            fwrite($sockets[0], "go\n");
            $read = [$sockets[0]];
            $write = null;
            $except = null;
            $this->assertSame(0, stream_select($read, $write, $except, 0, 100000));
            $this->connection->commit();

            $result = fgets($sockets[0]);
            pcntl_waitpid($pid, $status);
            $waited = true;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), (string) $result);
            $this->assertIsString($result);
            $this->assertSame(['id' => $id], json_decode($result, true, 512, JSON_THROW_ON_ERROR));
            $this->assertNull($this->otherStorage->claim(null, $this->now, $this->leaseUntil));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            fclose($sockets[0]);
            if (!$waited) {
                pcntl_waitpid($pid, $status);
            }
        }
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    private function insertJob(): int
    {
        $policy = new RetryPolicy();
        return $this->storage->insert('concurrency-test', 'test', '{}', $this->now, null, $policy, $this->now);
    }
}
