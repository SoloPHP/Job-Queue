<?php

declare(strict_types=1);

namespace Solo\JobQueue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use RuntimeException;

/**
 * Creates the jobs table for the target database platform.
 *
 * Supported:
 *   - MySQL 8.0+ / MariaDB 10.6+ (FOR UPDATE SKIP LOCKED)
 *   - PostgreSQL 9.5+
 *   - SQLite 3.35+
 */
final class Schema
{
    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public static function install(Connection $connection, string $table = 'jobs'): void
    {
        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof SQLitePlatform) {
            self::installSqlite($connection, $table);
            return;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            self::installPostgres($connection, $table);
            return;
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            self::installMysql($connection, $table);
            return;
        }

        throw new RuntimeException(sprintf(
            'Unsupported database platform: %s. Supported: MySQL 8+, MariaDB 10.6+, PostgreSQL 9.5+, SQLite 3.35+.',
            $platform::class
        ));
    }

    private static function installSqlite(Connection $connection, string $table): void
    {
        $connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS {$table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                type VARCHAR(64) NOT NULL DEFAULT 'default',
                payload TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                retry_count INTEGER NOT NULL DEFAULT 0,
                error TEXT NULL,
                scheduled_at DATETIME NOT NULL,
                locked_at DATETIME NULL,
                expires_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $connection->executeStatement(
            "CREATE INDEX IF NOT EXISTS idx_{$table}_status_scheduled ON {$table} (status, scheduled_at)"
        );
        $connection->executeStatement(
            "CREATE INDEX IF NOT EXISTS idx_{$table}_type ON {$table} (type)"
        );
        $connection->executeStatement(
            "CREATE INDEX IF NOT EXISTS idx_{$table}_locked_at ON {$table} (locked_at)"
        );
    }

    private static function installMysql(Connection $connection, string $table): void
    {
        $connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                type VARCHAR(64) NOT NULL DEFAULT 'default',
                payload JSON NOT NULL,
                status ENUM('pending', 'in_progress', 'completed', 'failed') NOT NULL DEFAULT 'pending',
                retry_count INT UNSIGNED NOT NULL DEFAULT 0,
                error TEXT NULL,
                scheduled_at DATETIME NOT NULL,
                locked_at DATETIME NULL,
                expires_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status_scheduled (status, scheduled_at),
                INDEX idx_type (type),
                INDEX idx_locked_at (locked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private static function installPostgres(Connection $connection, string $table): void
    {
        $connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS {$table} (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                type VARCHAR(64) NOT NULL DEFAULT 'default',
                payload JSONB NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                retry_count INTEGER NOT NULL DEFAULT 0,
                error TEXT NULL,
                scheduled_at TIMESTAMP NOT NULL,
                locked_at TIMESTAMP NULL,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $connection->executeStatement(
            "CREATE INDEX IF NOT EXISTS idx_{$table}_status_scheduled ON {$table} (status, scheduled_at)"
        );
        $connection->executeStatement(
            "CREATE INDEX IF NOT EXISTS idx_{$table}_type ON {$table} (type)"
        );
        $connection->executeStatement(
            "CREATE INDEX IF NOT EXISTS idx_{$table}_locked_at ON {$table} (locked_at)"
        );
    }
}
