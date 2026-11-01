# Solo Job Queue

Database-backed job queue for PHP on Doctrine DBAL: leased claims with ownership tokens, a retry policy and deadline stored with every job, and a worker daemon, on MySQL, MariaDB, PostgreSQL and SQLite.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/job-queue.svg)](https://packagist.org/packages/solophp/job-queue)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## Features

- **Leased Claims** — each attempt gets a random token and an expiring lease; only the current attempt can complete, fail or renew its job, so a stale worker cannot overwrite the state of the one that replaced it
- **Retries per Job** — attempts, exponential backoff and deadline are stored with each job when it is enqueued; no worker setting can change them later
- **Four Databases** — MySQL 8+, MariaDB 10.6+, PostgreSQL and SQLite 3.35+, with `SKIP LOCKED` claims, deadlock-free MySQL claims at READ COMMITTED, and retried lock conflicts
- **Maintenance** — abandoned attempts are recovered with each job's own policy, overdue jobs expire, and finished history is pruned in batches
- **Worker Daemon** — one job at a time, graceful shutdown on SIGTERM and SIGINT, job, runtime and memory limits, and timed maintenance
- **Observable** — PSR-3 logging with the exception under its key, listener events for metrics and tracing, and typed records for inspection
- **Upgradable** — `Schema::migrate()` converts a version 1 table in place, keeping IDs, payloads and history

## Installation

```bash
composer require solophp/job-queue
```

Requires PHP 8.3+ with `mbstring`, Doctrine DBAL 4 and a PDO driver. `pcntl` is optional and enables graceful shutdown.

## Quick Example

```php
use Solo\JobQueue\JobQueue;
use Solo\JobQueue\Codec\ContainerJobCodec;
use Solo\JobQueue\Storage\{DbalJobStorage, Schema};
use Solo\JobQueue\Worker\{Worker, WorkerLimits};

Schema::install($connection);

$queue = new JobQueue(
    storage: new DbalJobStorage($connection),
    codec: new ContainerJobCodec($container), // a PSR-11 container
);

// SendEmailJob implements Solo\JobQueue\Codec\PersistableJobInterface.
$queue->push(new SendEmailJob('user@example.com', 'Welcome'), 'email');

// In a long-running process under a supervisor:
$worker = new Worker(
    queue: $queue,
    type: 'email',
    limits: new WorkerLimits(maxJobs: 1000, maxRuntime: 3600),
);
$worker->run();
```

## Documentation

**[Full Documentation](https://solophp.github.io/Job-Queue/)**

- [Installation](https://solophp.github.io/Job-Queue/guide/installation)
- [Quick Start](https://solophp.github.io/Job-Queue/guide/quick-start)
- [Jobs and Codecs](https://solophp.github.io/Job-Queue/features/jobs)
- [Enqueueing](https://solophp.github.io/Job-Queue/features/enqueueing)
- [Processing and Leases](https://solophp.github.io/Job-Queue/features/processing)
- [Retries and Deadlines](https://solophp.github.io/Job-Queue/features/retries)
- [Worker](https://solophp.github.io/Job-Queue/features/worker)
- [Maintenance and Monitoring](https://solophp.github.io/Job-Queue/features/maintenance)
- [Delivery and Errors](https://solophp.github.io/Job-Queue/features/delivery)
- [Schema and Databases](https://solophp.github.io/Job-Queue/features/schema)
- [API Reference](https://solophp.github.io/Job-Queue/api/job-queue)

Upgrading from 1.x? See [UPGRADING.md](UPGRADING.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## Development

```bash
composer install
composer test # PSR-12, PHPStan, PHPUnit
```

The default suite uses SQLite, including file-backed two-connection contention when `pcntl` is available and a stress test in which four worker processes drain one queue and every job must run exactly once. CI also runs it on MySQL, MariaDB and PostgreSQL on PHP 8.3, 8.4 and 8.5.

For a disposable integration database:

```bash
docker compose -f compose.test.yaml up -d --wait
# Example: obtain the local MySQL port, then run the suite against the disposable database.
QUEUE_TEST_DRIVER=pdo_mysql \
QUEUE_TEST_HOST=127.0.0.1 \
QUEUE_TEST_PORT="$(docker compose -f compose.test.yaml port mysql 3306 | cut -d: -f2)" \
QUEUE_TEST_USER=queue_test QUEUE_TEST_PASSWORD=queue_test \
composer test-unit

docker compose -f compose.test.yaml down --volumes
```

**Use a dedicated test database:** the integration suite drops and recreates its `jobs` table. The default database name is `queue_test`; override it with `QUEUE_TEST_DB`. PostgreSQL uses the driver `pdo_pgsql` and its assigned port. The Compose services bind only to localhost and keep their data in temporary filesystems.

To preview the documentation site:

```bash
cd docs && npm install && npm run dev
```

## License

MIT — see [LICENSE](LICENSE).
