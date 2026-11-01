<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Solo\JobQueue\RetryPolicy;
use Solo\JobQueue\Schema;
use Solo\JobQueue\Storage\DbalJobStorage;

final class SchemaTest extends TestCase
{
    /** Sorted by name, as indexColumns() returns them. */
    private const V2_INDEXES = [
        'idx_jobs_lease_until' => ['lease_until'],
        'idx_jobs_status_expires' => ['status', 'expires_at'],
        'idx_jobs_status_scheduled' => ['status', 'scheduled_at', 'id'],
        'idx_jobs_type_status_scheduled' => ['type', 'status', 'scheduled_at', 'id'],
    ];

    public function testSqliteInstallCreatesTableAndIndexes(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        Schema::install($conn);
        Schema::install($conn); // idempotent

        $columns = array_keys($conn->createSchemaManager()->listTableColumns('jobs'));
        $expected = ['id', 'name', 'type', 'payload', 'status', 'attempts', 'claim_token', 'lease_until',
            'max_attempts', 'base_delay', 'max_delay', 'error', 'scheduled_at', 'expires_at', 'finished_at',
            'created_at'];
        foreach ($expected as $column) {
            $this->assertContains($column, $columns);
        }
        $this->assertSame(self::V2_INDEXES, $this->indexColumns($conn));
    }

    public function testMysqlInstallCreatesTheTableThenEachIndex(): void
    {
        $statements = $this->captureStatementsFor(AbstractMySQLPlatform::class);

        $this->assertCount(5, $statements);
        $this->assertStringContainsString('ENGINE=InnoDB', $statements[0]);
        $this->assertStringContainsString('CHARSET=utf8mb4', $statements[0]);
        $this->assertStringContainsString('payload JSON NOT NULL', $statements[0]);
        $this->assertStringContainsString('finished_at DATETIME NULL', $statements[0]);
        $this->assertStringNotContainsString('INDEX', $statements[0]);
        $this->assertSame([
            'CREATE INDEX idx_status_scheduled ON jobs (status, scheduled_at, id)',
            'CREATE INDEX idx_type_status_scheduled ON jobs (type, status, scheduled_at, id)',
            'CREATE INDEX idx_status_expires ON jobs (status, expires_at)',
            'CREATE INDEX idx_lease_until ON jobs (lease_until)',
        ], array_slice($statements, 1));
    }

    public function testPostgresInstallEmitsJsonbAndSchemaUniqueIndexNames(): void
    {
        $statements = $this->captureStatementsFor(PostgreSQLPlatform::class);

        $this->assertCount(5, $statements);
        $this->assertStringContainsString('BIGSERIAL', $statements[0]);
        $this->assertStringContainsString('JSONB NOT NULL', $statements[0]);
        $this->assertStringContainsString('finished_at TIMESTAMP NULL', $statements[0]);
        $this->assertSame([
            'CREATE INDEX idx_jobs_status_scheduled ON jobs (status, scheduled_at, id)',
            'CREATE INDEX idx_jobs_type_status_scheduled ON jobs (type, status, scheduled_at, id)',
            'CREATE INDEX idx_jobs_status_expires ON jobs (status, expires_at)',
            'CREATE INDEX idx_jobs_lease_until ON jobs (lease_until)',
        ], array_slice($statements, 1));
    }

    public function testUnsupportedPlatformThrows(): void
    {
        $platform = $this->createStub(AbstractPlatform::class);
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unsupported database platform/');

        Schema::install($connection);
    }

    public function testInstallRebuildsAnIndexWhoseColumnsDrifted(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        Schema::install($connection);
        $connection->executeStatement('DROP INDEX idx_jobs_status_scheduled');
        $connection->executeStatement('CREATE INDEX idx_jobs_status_scheduled ON jobs (status)');

        Schema::install($connection);

        $this->assertSame(self::V2_INDEXES, $this->indexColumns($connection));
    }

    public function testInstallRequiresMigrationForATableWithoutFinishTimes(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        Schema::install($connection);
        $connection->executeStatement('ALTER TABLE jobs DROP COLUMN finished_at');
        try {
            Schema::install($connection);
            $this->fail('A table without finished_at must be migrated first');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Schema::migrate', $error->getMessage());
        }

        Schema::migrate($connection, new RetryPolicy());
        Schema::install($connection);

        $this->assertArrayHasKey('finished_at', $connection->createSchemaManager()->listTableColumns('jobs'));
    }

    public function testMigrationPreservesLegacyJobsAndRecoversInterruptedAttempts(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement("CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255) NOT NULL,
            type VARCHAR(64) NOT NULL DEFAULT 'default', payload TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending', retry_count INTEGER NOT NULL DEFAULT 0,
            error TEXT NULL, scheduled_at DATETIME NOT NULL, locked_at DATETIME NULL,
            expires_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        // The indexes exactly as v1 created them.
        $connection->executeStatement('CREATE INDEX idx_jobs_status_scheduled ON jobs (status, scheduled_at)');
        $connection->executeStatement(
            'CREATE INDEX idx_jobs_type_status_scheduled ON jobs (type, status, scheduled_at)',
        );
        $connection->executeStatement('CREATE INDEX idx_jobs_locked_at ON jobs (locked_at)');
        $legacyRows = [
            ['pending', 1, null],
            ['in_progress', 0, null],
            ['in_progress', 2, null],
            ['completed', 1, null],
            ['failed', 3, null],
            ['pending', 0, '2000-01-01 00:00:00'],
            ['pending', 3, null],
        ];
        foreach ($legacyRows as [$status, $retries, $expiry]) {
            $connection->executeStatement(
                "INSERT INTO jobs (name, payload, status, retry_count, scheduled_at, locked_at, expires_at)
                 VALUES ('LegacyJob', '{}', ?, ?, '2000-01-01 00:00:00', '2000-01-01 00:00:00', ?)",
                [$status, $retries, $expiry],
            );
        }
        $policy = new RetryPolicy(maxAttempts: 3, baseDelay: 7, maxDelay: 70);
        Schema::migrate($connection, $policy);
        Schema::migrate($connection, $policy); // Re-running must not reset attempts or statuses.

        $rows = $connection->fetchAllAssociative(
            'SELECT status, attempts, max_attempts, base_delay, max_delay, finished_at FROM jobs ORDER BY id',
        );
        self::assertSame(
            ['pending', 'pending', 'failed', 'completed', 'failed', 'expired', 'failed'],
            array_column($rows, 'status'),
        );
        self::assertSame([1, 1, 3, 2, 3, 0, 3], array_map(intval(...), array_column($rows, 'attempts')));
        foreach ($rows as $row) {
            self::assertSame(3, (int) $row['max_attempts']);
            self::assertSame(7, (int) $row['base_delay']);
            self::assertSame(70, (int) $row['max_delay']);
        }
        // Unfinished jobs have no finish time, legacy finished jobs keep their last schedule,
        // and jobs finished by the migration get the migration time.
        $finished = array_column($rows, 'finished_at');
        self::assertSame([null, null], array_slice($finished, 0, 2));
        self::assertSame(['2000-01-01 00:00:00', '2000-01-01 00:00:00'], array_slice($finished, 3, 2));
        foreach ([2, 5, 6] as $migrated) {
            self::assertNotNull($finished[$migrated]);
            self::assertNotSame('2000-01-01 00:00:00', $finished[$migrated]);
        }

        $columns = $connection->createSchemaManager()->listTableColumns('jobs');
        self::assertArrayNotHasKey('retry_count', $columns);
        self::assertArrayNotHasKey('locked_at', $columns);
        self::assertSame(self::V2_INDEXES, $this->indexColumns($connection));
        $storage = new DbalJobStorage($connection);
        $now = new DateTimeImmutable('2099-01-01 00:00:00 UTC');
        $job = $storage->claim(null, $now, $now->modify('+1 minute'));
        self::assertNotNull($job);
        self::assertSame(2, $job->attempt);
        self::assertSame(3, $job->retryPolicy->maxAttempts);
    }

    public function testMigrationRejectsAnOuterTransactionBeforeDdl(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->beginTransaction();
        try {
            $this->expectException(RuntimeException::class);
            Schema::migrate($connection, new RetryPolicy());
        } finally {
            $connection->rollBack();
        }
    }

    public function testMigrationCanInstallAFreshTable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        Schema::migrate($connection, new RetryPolicy());
        self::assertArrayHasKey('attempts', $connection->createSchemaManager()->listTableColumns('jobs'));
    }

    /** @return array<string, list<string>> Index name => columns, sorted by name, without the primary key. */
    private function indexColumns(Connection $connection): array
    {
        $indexes = [];
        foreach ($connection->createSchemaManager()->listTableIndexes('jobs') as $name => $index) {
            if (!$index->isPrimary()) {
                $indexes[$name] = $index->getColumns();
            }
        }
        ksort($indexes);
        return $indexes;
    }

    /**
     * @param class-string<AbstractPlatform> $platformClass
     * @return list<string>
     */
    private function captureStatementsFor(string $platformClass): array
    {
        $platform = $this->createStub($platformClass);
        $platform->method('quoteSingleIdentifier')->willReturnCallback(static fn(string $name): string => $name);
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql) use (&$statements): int {
                $statements[] = preg_replace('/\s+/', ' ', $sql) ?? $sql;
                return 0;
            }
        );

        Schema::install($connection);
        return $statements;
    }
}
