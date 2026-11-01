# Installation

## Requirements

- PHP 8.3 or higher with the `mbstring` extension
- Doctrine DBAL 4 and a PDO driver for one of: SQLite 3.35+, MySQL 8+, MariaDB 10.6+, or a PostgreSQL version supported by your DBAL release
- Optional: `pcntl`, which lets the [worker](/features/worker) stop gracefully on SIGTERM and SIGINT

On MySQL or MariaDB with binary logging enabled, `binlog_format` must be `ROW` or `MIXED`, the defaults of both servers. See [Schema and Databases](/features/schema#mysql-and-mariadb).

## Composer

Install the package via Composer:

```bash
composer require solophp/job-queue
```

## Dependencies

```json
{
    "require": {
        "php": ">=8.3",
        "ext-mbstring": "*",
        "doctrine/dbal": "^4.0",
        "solophp/contracts": "^1.9",
        "psr/container": "^1.0|^2.0",
        "psr/log": "^1.0|^2.0|^3.0",
        "psr/clock": "^1.0"
    }
}
```

- **doctrine/dbal** — the connection; the queue writes its own SQL for each supported platform
- **solophp/contracts** — `JobInterface`, `JobQueueListener`, and the `JobProducerInterface` and `JobProcessorInterface` roles that `JobQueue` implements
- **psr/container** — [`ContainerJobCodec`](/features/jobs#containerjobcodec) restores jobs and their dependencies through a PSR-11 container
- **psr/log** and **psr/clock** — an optional PSR-3 logger and PSR-20 clock

## Create the Table

```php
use Doctrine\DBAL\DriverManager;
use Solo\JobQueue\Storage\Schema;

$connection = DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => 'localhost',
    'dbname' => 'app',
    'user' => 'app',
    'password' => 'secret',
]);

Schema::install($connection);          // the `jobs` table and its indexes
Schema::install($connection, 'queue'); // or a table of your own name
```

`install()` is idempotent: it creates a missing table, adds missing indexes and rebuilds an index whose columns changed, so it can run on every deploy. It refuses a table from version 1, which needs [`Schema::migrate()`](/features/schema#migrating-from-version-1) once instead.

## Build the Queue

```php
use Solo\JobQueue\{JobQueue, RetryPolicy};
use Solo\JobQueue\Codec\ContainerJobCodec;
use Solo\JobQueue\Storage\DbalJobStorage;

$queue = new JobQueue(
    storage: new DbalJobStorage($connection),
    codec: new ContainerJobCodec($container), // a PSR-11 container
    retry: new RetryPolicy(maxAttempts: 3, baseDelay: 60, maxDelay: 3600),
    leaseDuration: 600,
);
```

| Parameter | Default | Description |
|---|---|---|
| `storage` | — | A [`DbalJobStorage`](/api/storage#dbaljobstorage) for the connection and table |
| `codec` | — | Turns jobs into JSON and back: [`ContainerJobCodec`](/features/jobs#containerjobcodec) or your own [`JobCodecInterface`](/features/jobs#custom-codecs) |
| `retry` | `new RetryPolicy()` | The [retry policy](/features/retries) stored with each job this queue enqueues |
| `leaseDuration` | `600` | Seconds an attempt owns its job before maintenance may recover it |
| `deleteOnSuccess` | `false` | Delete a completed job instead of keeping its row |
| `logger` | `null` | A PSR-3 logger |
| `listener` | `null` | A [`JobQueueListener`](/features/maintenance#listener-events) for metrics and tracing |
| `clock` | the system clock, in UTC | A PSR-20 clock |

Creating the storage or the queue opens no database connection; the first query does.

## As a Service

`JobQueue` is final. It implements two interfaces from `solophp/contracts`; type-hint those instead:

```php
use Solo\Contracts\JobQueue\JobProducerInterface;

final class SignupService
{
    public function __construct(private JobProducerInterface $jobs)
    {
    }

    public function register(string $email): void
    {
        // ...
        $this->jobs->push(new SendEmailJob($email, 'Welcome'), 'email');
    }
}
```

- `JobProducerInterface` — `push()` and `pushMany()`, for services that only enqueue
- `JobProcessorInterface` — `processJobs()`, `processNext()` and `maintain()`, for workers and schedulers; [`Worker`](/features/worker) takes one

Both are easy to mock, and a package that integrates with the queue needs only `solophp/contracts`.

## Package Layout

| Namespace | Contents |
|---|---|
| `Solo\JobQueue` | `JobQueue` and its value types: `ClaimedJob`, `JobRecord`, `JobStatus`, `RetryPolicy` |
| `Solo\JobQueue\Codec` | `JobCodecInterface`, `ContainerJobCodec`, `PersistableJobInterface` |
| `Solo\JobQueue\Storage` | `DbalJobStorage` and `Schema` |
| `Solo\JobQueue\Worker` | The `Worker` daemon and its `WorkerLimits` |
| `Solo\Contracts\JobQueue` | `JobInterface`, `JobProducerInterface`, `JobProcessorInterface`, `JobQueueListener` |

## Next Steps

- [Quick Start](/guide/quick-start) — Define a job, enqueue it and run a worker
- [Jobs and Codecs](/features/jobs) — How jobs are stored and restored
- [Processing and Leases](/features/processing) — What a claim guarantees
