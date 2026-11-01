<?php

declare(strict_types=1);

namespace Solo\JobQueue\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use RuntimeException;
use Solo\JobQueue\RetryPolicy;

/** Creates v2 tables and explicitly migrates stopped v1 queues. */
final class Schema
{
    private const TABLE_NAME_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';
    private const REQUIRED_COLUMNS = [
        'attempts', 'claim_token', 'lease_until', 'max_attempts', 'base_delay', 'max_delay', 'finished_at',
    ];
    /** v1 columns; migrate() removes them only after converting their data. */
    private const LEGACY_COLUMNS = ['retry_count', 'locked_at'];
    /** PostgreSQL truncates longer identifiers. */
    private const POSTGRES_NAME_BYTES = 63;
    /** Index suffix => columns: the one definition used by install() and migrate(). */
    private const INDEXES = [
        'status_scheduled' => ['status', 'scheduled_at', 'id'],
        'type_status_scheduled' => ['type', 'status', 'scheduled_at', 'id'],
        'status_expires' => ['status', 'expires_at'],
        'lease_until' => ['lease_until'],
    ];

    /** @internal */
    public static function assertValidTableName(string $table): void
    {
        if (preg_match(self::TABLE_NAME_PATTERN, $table) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid table name "%s".', $table));
        }
    }

    /**
     * @internal The table as SQL addresses it. v1 interpolated names unquoted, which PostgreSQL
     * folds to lower case; quoting the folded name keeps addressing the same relation.
     */
    public static function quoteTable(Connection $connection, string $table): string
    {
        self::assertValidTableName($table);
        self::assertSupportedPlatform($connection);
        return self::quote($connection, self::fold($connection, $table));
    }

    private static function assertSupportedPlatform(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        if (
            !$platform instanceof SQLitePlatform
            && !$platform instanceof PostgreSQLPlatform
            && !$platform instanceof AbstractMySQLPlatform
        ) {
            throw new RuntimeException(sprintf(
                'Unsupported database platform: %s. Supported: MySQL 8+, MariaDB 10.6+, PostgreSQL, SQLite 3.35+.',
                $platform::class,
            ));
        }
    }

    public static function install(Connection $connection, string $table = 'jobs'): void
    {
        self::assertValidTableName($table);
        self::assertSupportedPlatform($connection);
        $table = self::fold($connection, $table);
        $indexes = self::indexes($connection, $table);
        $manager = $connection->createSchemaManager();
        if ($manager->tablesExist([$table])) {
            // Legacy columns also mark a MySQL migration interrupted after adding the new ones.
            $columns = array_keys($manager->listTableColumns($table));
            if (
                array_diff(self::REQUIRED_COLUMNS, $columns) !== []
                || array_intersect(self::LEGACY_COLUMNS, $columns) !== []
            ) {
                throw new RuntimeException('Outdated queue schema detected. Stop workers and run Schema::migrate().');
            }
        }
        $platform = $connection->getDatabasePlatform();
        $id = match (true) {
            $platform instanceof SQLitePlatform => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            $platform instanceof PostgreSQLPlatform => 'BIGSERIAL PRIMARY KEY',
            default => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
        };
        $payload = match (true) {
            $platform instanceof SQLitePlatform => 'TEXT',
            $platform instanceof PostgreSQLPlatform => 'JSONB',
            default => 'JSON',
        };
        $timestamp = $platform instanceof PostgreSQLPlatform ? 'TIMESTAMP' : 'DATETIME';
        $createdDefault = $platform instanceof PostgreSQLPlatform
            ? "(CURRENT_TIMESTAMP AT TIME ZONE 'UTC')" : 'CURRENT_TIMESTAMP';
        $suffix = $platform instanceof AbstractMySQLPlatform
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS ' . self::quote($connection, $table) . " (
                id {$id},
                name VARCHAR(255) NOT NULL,
                type VARCHAR(64) NOT NULL DEFAULT 'default',
                payload {$payload} NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                claim_token VARCHAR(64) NULL,
                lease_until {$timestamp} NULL,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                base_delay INTEGER NOT NULL DEFAULT 60,
                max_delay INTEGER NOT NULL DEFAULT 3600,
                error TEXT NULL,
                scheduled_at {$timestamp} NOT NULL,
                expires_at {$timestamp} NULL,
                finished_at {$timestamp} NULL,
                created_at {$timestamp} NOT NULL DEFAULT {$createdDefault}
            ){$suffix}",
        );
        self::installIndexes($connection, $table, $indexes);
    }

    /**
     * Stop all producers and consumers before migrating. The supplied policy is
     * persisted for every legacy job. An interrupted v1 attempt counts as used;
     * it is requeued immediately, failed if exhausted, or expired if overdue.
     * v1 kept no finish time: legacy completed/failed jobs get their last scheduled time.
     *
     * SQLite/PostgreSQL migrations are transactional. MySQL DDL commits implicitly:
     * take a backup first; an interrupted migration can be rerun with the same
     * policy while all producers/consumers remain stopped. Legacy columns are
     * removed only after their data has been converted.
     */
    public static function migrate(Connection $connection, RetryPolicy $legacyPolicy, string $table = 'jobs'): void
    {
        self::assertValidTableName($table);
        self::assertSupportedPlatform($connection);
        if ($connection->isTransactionActive() || !$connection->isAutoCommit()) {
            throw new RuntimeException('Queue schema migration requires autocommit without an active transaction.');
        }
        $table = self::fold($connection, $table);
        $indexes = self::indexes($connection, $table);
        if (!$connection->createSchemaManager()->tablesExist([$table])) {
            self::install($connection, $table);
            return;
        }
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $migration = static function () use ($connection, $legacyPolicy, $table, $indexes, $mysql): void {
            $quotedTable = self::quote($connection, $table);
            $columns = $connection->createSchemaManager()->listTableColumns($table);
            $timestamp = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'TIMESTAMP' : 'DATETIME';
            $definitions = [
                'attempts' => 'INTEGER NOT NULL DEFAULT 0',
                'claim_token' => 'VARCHAR(64) NULL',
                'lease_until' => "{$timestamp} NULL",
                'max_attempts' => 'INTEGER NOT NULL DEFAULT ' . $legacyPolicy->maxAttempts,
                'base_delay' => 'INTEGER NOT NULL DEFAULT ' . $legacyPolicy->baseDelay,
                'max_delay' => 'INTEGER NOT NULL DEFAULT ' . $legacyPolicy->maxDelay,
                'finished_at' => "{$timestamp} NULL",
            ];
            foreach ($definitions as $name => $definition) {
                if (!isset($columns[$name])) {
                    $connection->executeStatement("ALTER TABLE {$quotedTable} ADD COLUMN {$name} {$definition}");
                }
            }
            if (isset($columns['retry_count'])) {
                if ($mysql) {
                    // Convert the legacy TIMESTAMP to a UTC DATETIME while preserving its instant.
                    // New rows then have the same timezone-independent representation as fresh tables.
                    $timezone = $connection->fetchOne('SELECT @@session.time_zone');
                    if (!is_string($timezone)) {
                        throw new RuntimeException('Cannot read the MySQL session timezone for migration.');
                    }
                    $connection->executeStatement("SET time_zone = '+00:00'");
                    try {
                        $connection->executeStatement(
                            "ALTER TABLE {$quotedTable} MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending', "
                            . 'MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                        );
                    } finally {
                        $connection->executeStatement('SET time_zone = ?', [$timezone]);
                    }
                }
                $usedAttempts = "CASE WHEN attempts > 0 THEN attempts ELSE retry_count + "
                    . "CASE WHEN status IN ('in_progress', 'completed') THEN 1 ELSE 0 END END";
                $overdue = "status IN ('pending', 'in_progress') AND expires_at <= ?";
                $exhausted = "status IN ('pending', 'in_progress') AND ({$usedAttempts}) >= ?";
                $now = gmdate('Y-m-d H:i:s');
                // MySQL evaluates assignments left to right, so status must be assigned last.
                $connection->executeStatement(
                    "UPDATE {$quotedTable} SET attempts = {$usedAttempts}, max_attempts = ?, base_delay = ?, "
                    . 'max_delay = ?, claim_token = NULL, lease_until = NULL, '
                    . "error = CASE WHEN status = 'in_progress' "
                    . "THEN 'Interrupted by queue schema migration' ELSE error END, "
                    . "scheduled_at = CASE WHEN status = 'in_progress' THEN ? ELSE scheduled_at END, "
                    . "finished_at = COALESCE(finished_at, CASE WHEN {$overdue} OR {$exhausted} THEN ? "
                    . "WHEN status IN ('completed', 'failed') THEN scheduled_at END), "
                    . "status = CASE WHEN {$overdue} THEN 'expired' WHEN {$exhausted} THEN 'failed' "
                    . "WHEN status = 'in_progress' THEN 'pending' ELSE status END",
                    [$legacyPolicy->maxAttempts, $legacyPolicy->baseDelay, $legacyPolicy->maxDelay,
                        $now, $now, $legacyPolicy->maxAttempts, $now, $now, $legacyPolicy->maxAttempts],
                    [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER,
                        ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER,
                        ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
                );
            }
            // Drop indexes referencing the legacy lock column before dropping that column.
            foreach ($connection->createSchemaManager()->listTableIndexes($table) as $index) {
                if (in_array('locked_at', $index->getColumns(), true)) {
                    $connection->executeStatement(
                        'DROP INDEX ' . self::quote($connection, $index->getName())
                        . ($mysql ? " ON {$quotedTable}" : ''),
                    );
                }
            }
            foreach (self::LEGACY_COLUMNS as $legacyColumn) {
                if (isset($columns[$legacyColumn])) {
                    $connection->executeStatement("ALTER TABLE {$quotedTable} DROP COLUMN {$legacyColumn}");
                }
            }
            self::installIndexes($connection, $table, $indexes);
        };
        if ($mysql) {
            $migration();
        } else {
            $connection->transactional($migration);
        }
    }

    /**
     * Index name => columns. MySQL index names are per table; elsewhere they share one namespace
     * per schema. PostgreSQL names are truncated as PostgreSQL truncated the v1 index names.
     *
     * @return array<string, list<string>>
     */
    private static function indexes(Connection $connection, string $table): array
    {
        $platform = $connection->getDatabasePlatform();
        $indexes = [];
        foreach (self::INDEXES as $suffix => $columns) {
            $name = $platform instanceof AbstractMySQLPlatform ? 'idx_' . $suffix : 'idx_' . $table . '_' . $suffix;
            $indexes[$platform instanceof PostgreSQLPlatform ? substr($name, 0, self::POSTGRES_NAME_BYTES) : $name]
                = $columns;
        }
        if (count($indexes) !== count(self::INDEXES)) {
            throw new InvalidArgumentException(
                sprintf('Table name "%s" is too long for distinct PostgreSQL index names.', $table),
            );
        }
        return $indexes;
    }

    /**
     * Create missing indexes and rebuild those whose columns differ from the definition.
     *
     * @param array<string, list<string>> $indexes
     */
    private static function installIndexes(Connection $connection, string $table, array $indexes): void
    {
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $existing = $connection->createSchemaManager()->listTableIndexes($table);
        $quotedTable = self::quote($connection, $table);
        foreach ($indexes as $name => $columns) {
            $index = $existing[strtolower($name)] ?? null;
            if ($index !== null && $index->getColumns() === $columns) {
                continue;
            }
            $quotedIndex = self::quote($connection, $name);
            if ($index !== null) {
                // v1 created indexes under these names without the id tiebreaker.
                $connection->executeStatement("DROP INDEX {$quotedIndex}" . ($mysql ? " ON {$quotedTable}" : ''));
            }
            $connection->executeStatement(
                "CREATE INDEX {$quotedIndex} ON {$quotedTable} (" . implode(', ', $columns) . ')',
            );
        }
    }

    private static function quote(Connection $connection, string $identifier): string
    {
        return $connection->getDatabasePlatform()->quoteSingleIdentifier($identifier);
    }

    private static function fold(Connection $connection, string $identifier): string
    {
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? strtolower($identifier) : $identifier;
    }
}
