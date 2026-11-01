<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\TestCase;
use Solo\JobQueue\Codec\ContainerJobCodec;
use Solo\JobQueue\Storage\DbalJobStorage;
use Solo\JobQueue\Storage\Schema;

/**
 * Common test scaffolding: in-memory SQLite (or the QUEUE_TEST_* database) + installed
 * schema + ready-to-use DbalJobStorage. Concrete test cases compose `JobQueue` / `Worker` on top.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;
    protected DbalJobStorage $storage;
    protected Recorder $recorder;
    protected ArrayContainer $container;
    private ?string $sqlitePath = null;

    protected function setUp(): void
    {
        $driver = getenv('QUEUE_TEST_DRIVER') ?: 'pdo_sqlite';
        $params = $driver === 'pdo_sqlite' ? ['driver' => $driver, 'memory' => true] : [
            'driver' => $driver,
            'host' => getenv('QUEUE_TEST_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('QUEUE_TEST_PORT') ?: ($driver === 'pdo_pgsql' ? 5432 : 3306)),
            'dbname' => getenv('QUEUE_TEST_DB') ?: 'queue_test',
            'user' => getenv('QUEUE_TEST_USER') ?: 'root',
            'password' => getenv('QUEUE_TEST_PASSWORD') ?: 'queue_test',
        ];
        $this->connection = DriverManager::getConnection($params);
        $this->connection->executeStatement('DROP TABLE IF EXISTS jobs');
        Schema::install($this->connection);

        $this->storage = new DbalJobStorage($this->connection);
        $this->recorder = new Recorder();
        $this->container = new ArrayContainer([Recorder::class => $this->recorder]);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        if ($this->sqlitePath !== null) {
            foreach ([$this->sqlitePath, $this->sqlitePath . '-wal', $this->sqlitePath . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        parent::tearDown();
    }

    protected function codec(): ContainerJobCodec
    {
        return new ContainerJobCodec($this->container);
    }

    /** Independent connections and processes cannot share an in-memory SQLite database. */
    protected function useSqliteFile(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }
        $this->connection->close();
        $path = tempnam(sys_get_temp_dir(), 'job-queue-');
        if ($path === false) {
            $this->fail('Could not create the SQLite test database');
        }
        $this->sqlitePath = $path;
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $this->connection->executeStatement('PRAGMA busy_timeout = 5000');
        Schema::install($this->connection);
        $this->storage = new DbalJobStorage($this->connection);
    }
}
