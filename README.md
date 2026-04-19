# JobQueue

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/job-queue.svg)](https://packagist.org/packages/solophp/job-queue)
[![License](https://img.shields.io/packagist/l/solophp/job-queue.svg)](https://github.com/solophp/job-queue/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/job-queue.svg)](https://packagist.org/packages/solophp/job-queue)

A small, production-ready, database-backed job queue for PHP.

Correctness-first: atomic claim, visibility timeout for dead workers, exponential backoff on failures, no long-lived transactions. About ~300 lines across three classes.

## Features

- **Atomic claim** via `FOR UPDATE SKIP LOCKED` (MySQL 8+, MariaDB 10.6+, PostgreSQL 9.5+); on SQLite the single-writer model provides the same guarantee.
- **Visibility timeout** — jobs whose worker died (crashed, OOM-killed, timed out) are automatically returned to the queue.
- **Exponential backoff** — retries are rescheduled with `baseDelay * 2^retry_count`, capped at 1 hour.
- **No long transactions** — `handle()` runs outside any transaction, so external side-effects cannot be rolled back.
- **Typed jobs** via `JobInterface` + PSR-11 dependency injection through `createFromContainer`.
- **UTC timestamps** — all DB times written in UTC regardless of the PHP default timezone.
- **PSR-3 logging** with full-fidelity error context — Throwables are forwarded under the PSR-3 `exception` key (trace + previous chain preserved for structured log sinks).
- **Optional `deleteOnSuccess`** and **`LockGuard`** for preventing overlapping workers.

## Requirements

- PHP **>= 8.2**
- `ext-json`
- `doctrine/dbal` ^4
- One of: MySQL 8+, MariaDB 10.6+, PostgreSQL 9.5+, SQLite 3.35+

## Installation

```bash
composer require solophp/job-queue
```

## Setup

```php
use Doctrine\DBAL\DriverManager;
use Solo\JobQueue\JobQueue;
use Solo\JobQueue\Schema;

$connection = DriverManager::getConnection([
    'driver'   => 'pdo_mysql',
    'host'     => 'localhost',
    'dbname'   => 'app',
    'user'     => 'app',
    'password' => '***',
]);

Schema::install($connection); // create the `jobs` table (idempotent)

$queue = new JobQueue(
    connection:       $connection,
    table:            'jobs',
    maxRetries:       3,     // permanent-fail after N attempts
    lockTimeout:      600,   // seconds before a stuck job is reclaimed
    baseRetryDelay:   60,    // seconds — first retry delay (doubles each time)
    deleteOnSuccess:  false, // delete successful jobs instead of keeping history
    container:        $container, // PSR-11 container, used by createFromContainer
    logger:           $logger,    // optional PSR-3 logger
);
```

## Defining a job

Every job implements `Solo\Contracts\JobQueue\JobInterface` and provides a static `createFromContainer(ContainerInterface $container, array $data): self` factory. This lets the queue persist *only* job data (not services) and wire up dependencies at run time.

```php
use Psr\Container\ContainerInterface;
use Solo\Contracts\JobQueue\JobInterface;

final class SendEmailJob implements JobInterface, \JsonSerializable
{
    private ?Mailer $mailer = null;

    public function __construct(
        private readonly string $to,
        private readonly string $subject,
        private readonly string $body,
    ) {}

    public static function createFromContainer(ContainerInterface $c, array $data): self
    {
        $job = new self($data['to'], $data['subject'], $data['body']);
        $job->mailer = $c->get(Mailer::class);
        return $job;
    }

    public function jsonSerialize(): array
    {
        return ['to' => $this->to, 'subject' => $this->subject, 'body' => $this->body];
    }

    public function handle(): void
    {
        $this->mailer->send($this->to, $this->subject, $this->body);
    }
}
```

## Pushing jobs

```php
$queue->push(new SendEmailJob('user@example.com', 'Welcome', 'Hi!'));

// With a type (useful for running per-queue workers)
$queue->push($job, 'email');

// Scheduled for later
$queue->push($job, 'email', scheduledAt: new DateTimeImmutable('+1 hour'));

// With an expiration — dropped if not executed in time
$queue->push($job, 'email',
    scheduledAt: new DateTimeImmutable(),
    expiresAt:   new DateTimeImmutable('+1 day'),
);
```

## Processing jobs

```php
$queue->processJobs(10);              // up to 10 jobs of any type
$queue->processJobs(10, 'email');     // only 'email' jobs
```

What `processJobs()` does, in order:

1. **Reclaim stuck jobs** — any job stuck in `in_progress` longer than `lockTimeout` is returned to `pending` (or marked `failed` if retries are exhausted).
2. **Atomic claim** — a short transaction selects up to `$limit` pending jobs with `FOR UPDATE SKIP LOCKED` and marks them `in_progress`. Other workers skip these rows immediately.
3. **Run each job** outside any transaction — `handle()` executes, then a single `UPDATE` marks the job `completed` or `failed`. Side-effects are never rolled back.

On a thrown exception the job is rescheduled with exponential backoff. After `maxRetries` attempts it becomes `failed` permanently.

## Delivery semantics: at-least-once

**Jobs can run more than once.** `handle()` runs outside any transaction, and `markCompleted()` is a separate statement. If a worker crashes (or the DB connection drops) *after* `handle()` succeeds but *before* `markCompleted()` finishes, the job stays `in_progress`. Once `lockTimeout` elapses, `reclaimStuck` returns it to the queue and another worker runs `handle()` again.

This is the standard at-least-once guarantee. **Make every handler idempotent** — external side effects (emails sent, API calls, row inserts) must tolerate being repeated:

- Use deduplication keys on outbound APIs (idempotency tokens).
- Prefer `INSERT ... ON CONFLICT` / upserts over raw inserts.
- For emails, check a "sent" flag before sending.
- For financial operations, use transaction references.

If you cannot make a handler idempotent, keep side effects minimal and accept the trade-off.

## Timezones

All internal timestamps (`scheduled_at`, `locked_at`, `expires_at`, `created_at` defaults) are written in **UTC** regardless of the PHP default timezone. Compare timestamps in UTC on the database side too. `DateTimeImmutable` arguments you pass to `push()`/`addJob()` are converted to UTC internally.

## Preventing overlapping workers

Use `LockGuard` in cron-driven workers to avoid two instances of the same script running concurrently. It uses `flock()` — atomic, cross-platform, auto-released by the OS on process exit.

```php
use Solo\JobQueue\LockGuard;

$lock = new LockGuard(__DIR__ . '/storage/locks/worker.lock');

if (!$lock->acquire()) {
    exit(0); // another worker is already running
}

$queue->processJobs(50);
// $lock->release();  // optional — destructor releases it
```

## Integration with Async Event-Dispatcher

JobQueue integrates with [SoloPHP Async Event-Dispatcher](https://github.com/SoloPHP/async-event-dispatcher):

```php
use Solo\AsyncEventDispatcher\{AsyncEventDispatcher, ReferenceListenerRegistry, ListenerReference};
use Solo\AsyncEventDispatcher\Adapter\SoloJobQueueAdapter;

$registry = new ReferenceListenerRegistry();
$registry->addReference(UserRegistered::class, new ListenerReference(SendWelcomeEmail::class, 'handle'));

$dispatcher = new AsyncEventDispatcher($registry, new SoloJobQueueAdapter($queue, $container));
$dispatcher->dispatch(new UserRegistered('john@example.com'));

$queue->processJobs(10, 'async_event');
```

## API

| Method | Description |
|---|---|
| `Schema::install($connection, $table = 'jobs')` | Create the jobs table (idempotent). |
| `push(JobInterface $job, ?string $type = null, ?DateTimeImmutable $scheduledAt = null, ?DateTimeImmutable $expiresAt = null): int` | Enqueue a typed job. |
| `addJob(array $payload, ?DateTimeImmutable $scheduledAt = null, ?DateTimeImmutable $expiresAt = null, ?string $type = null): int` | Enqueue a raw payload (must contain `job_class`). |
| `processJobs(int $limit = 10, ?string $onlyType = null): void` | Reclaim stuck jobs, then claim and run pending jobs. |
| `getPendingJobs(int $limit = 10, ?string $onlyType = null): array` | Informational read of pending jobs. Does not lock rows. |
| `markCompleted(int $jobId): void` | Mark a job completed (or delete it if `deleteOnSuccess = true`). |
| `markFailed(int $jobId, Throwable\|string $error = ''): void` | Record failure; reschedules with backoff or marks `failed` if retries exhausted. Passing a `Throwable` captures class/file/line in the DB `error` column and forwards the exception to the logger under the PSR-3 `exception` key. |

## Testing

```bash
composer install
composer test            # cs-check + phpstan + phpunit
composer test-unit       # phpunit only
```

Tests run against SQLite in-memory, covering atomic claim, retry/backoff, visibility timeout, type filtering, expiry, DI wiring, validation branches and logger integration. Schema SQL for MySQL and PostgreSQL is tested via mocked connections.

## License

MIT — see [LICENSE](./LICENSE).
