<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Solo\JobQueue\RetryPolicy;

/**
 * Several worker processes drain one queue at once. With REPEATABLE READ gap locks, MySQL and
 * MariaDB used to deadlock concurrent claims here within seconds.
 */
final class StressTest extends QueueTestCase
{
    private const WORKERS = 4;
    private const JOBS = 1200;

    public function testConcurrentWorkersExecuteEveryJobExactlyOnce(): void
    {
        $this->useSqliteFile();
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->connection->executeStatement('PRAGMA journal_mode = WAL');
        }
        $past = new DateTimeImmutable('2000-01-01 00:00:00 UTC');
        $rows = [];
        for ($number = 0; $number < self::JOBS; ++$number) {
            $rows[] = ['stress', json_encode(['n' => $number], JSON_THROW_ON_ERROR)];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->storage->insertMany($chunk, 'default', $past, null, new RetryPolicy(), $past);
        }

        $env = ['QUEUE_STRESS_PARAMS' => json_encode($this->connection->getParams(), JSON_THROW_ON_ERROR)] + getenv();
        $workers = [];
        for ($i = 0; $i < self::WORKERS; ++$i) {
            // A file, not a pipe: an unread stderr pipe could fill up and block the worker.
            $errors = tmpfile();
            $this->assertIsResource($errors);
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/Fixtures/stress-worker.php'],
                [1 => ['pipe', 'w'], 2 => $errors],
                $pipes,
                null,
                $env,
            );
            $this->assertIsResource($process);
            $workers[] = [$process, $pipes[1], $errors];
        }

        $executed = [];
        foreach ($workers as [$process, $stdout, $errors]) {
            $output = (string) stream_get_contents($stdout);
            fclose($stdout);
            $exitCode = proc_close($process);
            rewind($errors);
            $this->assertSame(0, $exitCode, $output . stream_get_contents($errors));
            fclose($errors);
            /** @var list<int> $numbers */
            $numbers = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            array_push($executed, ...$numbers);
        }

        sort($executed);
        $this->assertSame(range(0, self::JOBS - 1), $executed);
        $this->assertSame(self::JOBS, $this->storage->countByStatus(null)['completed']);
    }
}
