<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Solo\JobQueue\Schema;

final class SchemaTest extends TestCase
{
    public function testSqliteInstallCreatesTableAndIndexes(): void
    {
        $conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        Schema::install($conn);
        Schema::install($conn); // idempotent

        $columns = array_keys($conn->createSchemaManager()->listTableColumns('jobs'));
        foreach (['id', 'name', 'type', 'payload', 'status', 'retry_count', 'error', 'scheduled_at', 'locked_at', 'expires_at', 'created_at'] as $expected) {
            $this->assertContains($expected, $columns);
        }

        $indexes = array_keys($conn->createSchemaManager()->listTableIndexes('jobs'));
        $this->assertContains('idx_jobs_status_scheduled', $indexes);
        $this->assertContains('idx_jobs_type', $indexes);
        $this->assertContains('idx_jobs_locked_at', $indexes);
    }

    public function testMysqlInstallEmitsInnoDbCreateStatement(): void
    {
        $statements = $this->captureStatementsFor(AbstractMySQLPlatform::class);

        $this->assertCount(1, $statements, 'MySQL install must use a single CREATE TABLE with inline indexes');
        $sql = $statements[0];
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('CHARSET=utf8mb4', $sql);
        $this->assertStringContainsString('payload JSON NOT NULL', $sql);
        $this->assertStringContainsString('INDEX idx_status_scheduled', $sql);
    }

    public function testPostgresInstallEmitsJsonbAndSeparateIndexes(): void
    {
        $statements = $this->captureStatementsFor(PostgreSQLPlatform::class);

        $this->assertGreaterThanOrEqual(4, count($statements), 'Postgres install must emit CREATE TABLE + 3 CREATE INDEX');

        $joined = implode("\n", $statements);
        $this->assertStringContainsString('BIGSERIAL', $joined);
        $this->assertStringContainsString('JSONB NOT NULL', $joined);
        $this->assertStringContainsString('idx_jobs_status_scheduled', $joined);
        $this->assertStringContainsString('idx_jobs_type', $joined);
        $this->assertStringContainsString('idx_jobs_locked_at', $joined);
    }

    public function testUnsupportedPlatformThrows(): void
    {
        $platform = $this->createMock(AbstractPlatform::class);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unsupported database platform/');

        Schema::install($connection);
    }

    /**
     * @param class-string<AbstractPlatform> $platformClass
     * @return list<string>
     */
    private function captureStatementsFor(string $platformClass): array
    {
        $platform = $this->createMock($platformClass);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql) use (&$statements): int {
                $statements[] = $sql;
                return 0;
            }
        );

        Schema::install($connection);
        return $statements;
    }
}
