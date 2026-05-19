<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Solo\JobQueue\Schema;
use Solo\JobQueue\Storage\DbalJobStorage;
use Solo\JobQueue\Storage\JobStorageInterface;
use Solo\JobQueue\Tests\Fixtures\ArrayContainer;
use Solo\JobQueue\Tests\Fixtures\Recorder;

/**
 * Common test scaffolding: in-memory SQLite + installed schema + ready-to-use
 * DbalJobStorage. Concrete test cases compose `JobQueue` / `Worker` on top.
 */
abstract class QueueTestCase extends TestCase
{
    protected Connection $connection;
    protected JobStorageInterface $storage;
    protected Recorder $recorder;
    protected ArrayContainer $container;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        Schema::install($this->connection);

        $this->storage = new DbalJobStorage($this->connection);
        $this->recorder = new Recorder();
        $this->container = new ArrayContainer([Recorder::class => $this->recorder]);
    }
}
