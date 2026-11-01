<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use RuntimeException;
use Solo\JobQueue\{JobQueue, RetryPolicy, Schema};
use Solo\JobQueue\Storage\DbalJobStorage;
use Solo\JobQueue\Tests\Fixtures\SimpleJob;

/** Runs legacy schema upgrades on every real database in the integration matrix. */
final class MigrationTest extends QueueTestCase
{
    private string $indexPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection->executeStatement('DROP TABLE jobs');
        $this->indexPrefix = $this->createV1Table('jobs');
    }

    public function testLegacyPayloadsAndAttemptHistorySurviveMigrationAndRerun(): void
    {
        $codec = $this->codec();
        $legacyRows = [
            ['pending', 0, null],
            ['in_progress', 0, null],
            ['in_progress', 2, null],
            ['completed', 1, null],
            ['failed', 3, null],
            ['pending', 0, '2000-01-01 00:00:00'],
        ];
        foreach ($legacyRows as [$status, $count, $expires]) {
            $this->connection->executeStatement(
                'INSERT INTO jobs (name, payload, status, retry_count, scheduled_at, locked_at, expires_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
                [SimpleJob::class, $codec->encode(new SimpleJob($status)), $status, $count,
                    '2000-01-01 00:00:00', '2000-01-01 00:00:00', $expires],
            );
        }
        $policy = new RetryPolicy(maxAttempts: 3, baseDelay: 7, maxDelay: 70);
        Schema::migrate($this->connection, $policy);
        $before = $this->connection->fetchAllAssociative('SELECT * FROM jobs ORDER BY id');
        Schema::migrate($this->connection, $policy);
        Schema::install($this->connection);
        $this->assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM jobs ORDER BY id'));
        $this->assertSame(
            ['pending', 'pending', 'failed', 'completed', 'failed', 'expired'],
            array_column($before, 'status'),
        );
        $this->assertSame([0, 1, 3, 2, 3, 0], array_map(intval(...), array_column($before, 'attempts')));
        $this->assertSame([3, 3, 3, 3, 3, 3], array_map(intval(...), array_column($before, 'max_attempts')));
        $finished = array_column($before, 'finished_at');
        $this->assertSame([null, null], array_slice($finished, 0, 2));
        $this->assertSame(['2000-01-01 00:00:00', '2000-01-01 00:00:00'], array_slice($finished, 3, 2));
        $this->assertNotNull($finished[2]);
        $this->assertNotNull($finished[5]);
        $this->assertIndexesMatchTheV2Definition();

        $queue = new JobQueue($this->storage, $codec);
        $this->assertSame(2, $queue->processJobs());
        $this->assertSame(['pending', 'in_progress'], $this->recorder->items);
        $newId = $queue->push(new SimpleJob('new'));
        $this->assertGreaterThan((int) end($before)['id'], $newId);
        $this->assertSame(1, $queue->processJobs());
    }

    public function testMysqlCreationTimesRemainUtcAfterMigrationAcrossSessionTimezones(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->markTestSkipped('Legacy MySQL TIMESTAMP conversion');
        }
        $this->connection->executeStatement("SET time_zone = '+02:00'");
        $this->connection->executeStatement(
            "INSERT INTO jobs (name, payload, scheduled_at, created_at) "
            . "VALUES ('old', '{}', '2026-01-01 00:00:00', '2026-01-01 10:00:00')",
        );
        Schema::migrate($this->connection, new RetryPolicy());
        $this->assertSame('+02:00', $this->connection->fetchOne('SELECT @@session.time_zone'));
        $this->assertSame('2026-01-01 08:00:00', $this->connection->fetchOne('SELECT created_at FROM jobs'));
        $now = new DateTimeImmutable('2030-01-01 00:00:00 UTC');
        $id = $this->storage->insert('new', 'default', '{}', $now, null, new RetryPolicy(), $now);
        $createdAt = $this->connection->fetchOne('SELECT created_at FROM jobs WHERE id = ?', [$id]);
        $this->connection->executeStatement("SET time_zone = '-05:00'");
        $this->assertSame('2030-01-01 00:00:00', $createdAt);
        $this->assertSame($createdAt, $this->connection->fetchOne('SELECT created_at FROM jobs WHERE id = ?', [$id]));
    }

    public function testInstallReportsThatLegacyTableRequiresMigration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Schema::migrate');
        Schema::install($this->connection);
    }

    public function testMigrationResumesAfterPolicyColumnsWereAdded(): void
    {
        $this->connection->executeStatement('ALTER TABLE jobs ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0');
        $this->connection->executeStatement('ALTER TABLE jobs ADD COLUMN max_attempts INTEGER NOT NULL DEFAULT 3');
        $this->connection->executeStatement(
            "INSERT INTO jobs (name, payload, status, retry_count, scheduled_at) "
            . "VALUES ('old', '{}', 'in_progress', 1, '2000-01-01 00:00:00')",
        );
        Schema::migrate($this->connection, new RetryPolicy(maxAttempts: 5));
        $row = $this->connection->fetchAssociative('SELECT attempts, max_attempts, status, finished_at FROM jobs');
        $this->assertIsArray($row);
        $this->assertSame(2, (int) $row['attempts']);
        $this->assertSame(5, (int) $row['max_attempts']);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['finished_at']);
        $this->assertIndexesMatchTheV2Definition();
    }

    public function testLongMixedCaseTableNameKeepsAddressingTheTableV1Created(): void
    {
        // PostgreSQL folded v1's unquoted name to lower case and truncated its index names to 63 bytes.
        $table = 'Legacy_' . str_repeat('queue_', 6);
        $this->connection->executeStatement("DROP TABLE IF EXISTS {$table}");
        try {
            $this->createV1Table($table);
            Schema::migrate($this->connection, new RetryPolicy(), $table);
            Schema::install($this->connection, $table);

            $storage = new DbalJobStorage($this->connection, $table);
            $now = new DateTimeImmutable('2030-01-01 00:00:00 UTC');
            $id = $storage->insert('job', 'default', '{}', $now, null, new RetryPolicy(), $now);
            $claim = $storage->claim(null, $now, $now->modify('+1 minute'));
            $this->assertNotNull($claim);
            $this->assertSame($id, $claim->id);
            $this->assertTrue($storage->complete($claim, $now, false));
            $this->assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$table}"));
        } finally {
            $this->connection->executeStatement("DROP TABLE IF EXISTS {$table}");
        }
    }

    /** Creates a table and its indexes exactly as v1 did, with unquoted names; returns the index prefix. */
    private function createV1Table(string $table): string
    {
        $platform = $this->connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;
        $postgres = $platform instanceof PostgreSQLPlatform;
        $id = match (true) {
            $mysql => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            $postgres => 'BIGSERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $payload = $mysql ? 'JSON' : ($postgres ? 'JSONB' : 'TEXT');
        $date = $postgres ? 'TIMESTAMP' : 'DATETIME';
        $created = $mysql ? 'TIMESTAMP' : $date;
        $status = $mysql ? "ENUM('pending','in_progress','completed','failed')" : 'VARCHAR(20)';
        $this->connection->executeStatement("CREATE TABLE {$table} (
            id {$id}, name VARCHAR(255) NOT NULL, type VARCHAR(64) NOT NULL DEFAULT 'default',
            payload {$payload} NOT NULL, status {$status} NOT NULL DEFAULT 'pending',
            retry_count INTEGER NOT NULL DEFAULT 0, error TEXT NULL, scheduled_at {$date} NOT NULL,
            locked_at {$date} NULL, expires_at {$date} NULL,
            created_at {$created} NOT NULL DEFAULT CURRENT_TIMESTAMP
        )" . ($mysql ? ' ENGINE=InnoDB' : ''));
        // Per-table index names on MySQL, prefixed elsewhere.
        $p = $mysql ? 'idx_' : "idx_{$table}_";
        $this->connection->executeStatement("CREATE INDEX {$p}status_scheduled ON {$table} (status, scheduled_at)");
        $this->connection->executeStatement(
            "CREATE INDEX {$p}type_status_scheduled ON {$table} (type, status, scheduled_at)",
        );
        $this->connection->executeStatement("CREATE INDEX {$p}locked_at ON {$table} (locked_at)");
        return $p;
    }

    private function assertIndexesMatchTheV2Definition(): void
    {
        $indexes = $this->connection->createSchemaManager()->listTableIndexes('jobs');
        $p = $this->indexPrefix;
        $expected = [
            'status_scheduled' => ['status', 'scheduled_at', 'id'],
            'type_status_scheduled' => ['type', 'status', 'scheduled_at', 'id'],
            'status_expires' => ['status', 'expires_at'],
            'lease_until' => ['lease_until'],
        ];
        foreach ($expected as $suffix => $columns) {
            $this->assertArrayHasKey($p . $suffix, $indexes);
            $this->assertSame($columns, $indexes[$p . $suffix]->getColumns(), $p . $suffix);
        }
        $this->assertArrayNotHasKey($p . 'locked_at', $indexes);
    }
}
