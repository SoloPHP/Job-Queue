# JobQueue

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/job-queue.svg)](https://packagist.org/packages/solophp/job-queue)
[![License](https://img.shields.io/packagist/l/solophp/job-queue.svg)](https://github.com/solophp/job-queue/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/job-queue.svg)](https://packagist.org/packages/solophp/job-queue)

A small, production-ready, database-backed job queue for PHP.

Correctness-first: atomic claim, visibility timeout for dead workers, exponential backoff on failures, no long-lived transactions. Small surface area, no framework lock-in.

## Features

- **Atomic claim** via `FOR UPDATE SKIP LOCKED` (MySQL 8+, MariaDB 10.6+, PostgreSQL 9.5+); on SQLite the single-writer model provides the same guarantee.
- **Visibility timeout** — jobs whose worker died (crashed, OOM-killed, timed out) are automatically returned to the queue.
- **Exponential backoff** — retries are rescheduled with `baseDelay * 2^retry_count`, capped at 1 hour.
- **No long transactions** — `handle()` runs outside any transaction, so external side-effects cannot be rolled back.
- **Typed jobs** via `JobInterface` + PSR-11 dependency injection through `createFromContainer`.
- **UTC timestamps** — all DB times written in UTC regardless of the PHP default timezone.
- **PSR-3 logging** with full-fidelity error context — Throwables are forwarded under the PSR-3 `exception` key (trace + previous chain preserved for structured log sinks).
- **Observability hooks** via `JobQueueListener` — `onClaimed` / `onCompleted` / `onFailed` / `onReclaimed` events for metrics, tracing, or context enrichment.
- **Long-running worker** with signal-driven graceful shutdown and max-jobs / max-runtime / max-memory limits.
- **Operational API** — `reclaimStuck()` (callable from a separate cron), `getFailedJobs()`, `retry()`.
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
use Solo\JobQueue\RetryPolicy;
use Solo\JobQueue\Schema;
use Solo\JobQueue\Storage\DbalJobStorage;

$connection = DriverManager::getConnection([
    'driver'   => 'pdo_mysql',
    'host'     => 'localhost',
    'dbname'   => 'app',
    'user'     => 'app',
    'password' => '***',
]);

Schema::install($connection); // create the `jobs` table (idempotent)

$queue = new JobQueue(
    storage:          new DbalJobStorage($connection, 'jobs'),
    retry:            new RetryPolicy(
        maxRetries:  3,     // permanent-fail after N attempts
        lockTimeout: 600,   // seconds before a stuck job is reclaimed
        baseDelay:   60,    // seconds — first retry delay (doubles each time)
        maxDelay:    3600,  // hard cap on backoff
    ),
    deleteOnSuccess:  false, // delete successful jobs instead of keeping history
    container:        $container, // PSR-11 container, used by createFromContainer
    logger:           $logger,    // optional PSR-3 logger
    listener:         $listener,  // optional JobQueueListener for metrics / tracing
    autoReclaim:      true,       // call reclaimStuck() automatically from processJobs()
);

// Or with a preset:
$queue = new JobQueue(storage: $storage, retry: RetryPolicy::aggressive(), ...);
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

// Bulk-insert many jobs sharing the same type/schedule in a single statement
$jobs = array_map(fn(array $chunk) => new ChunkJob($chunk), array_chunk($ids, 500));
$queue->pushMany($jobs, 'storefront-resync');
```

## Monitoring progress

```php
$stats = $queue->getStats('storefront-resync');
// ['pending' => 7, 'in_progress' => 1, 'completed' => 192, 'failed' => 0]
```

`getStats()` is the recommended way to track batch progress: push N jobs with a shared `type`, then poll `getStats($type)`. No batch table, no extra schema — the type column is the grouping key.

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

## Long-running worker

For production, prefer the `Worker` daemon over a cron tick. It blocks on the queue, processes batches, sleeps when empty, and shuts down cleanly on `SIGTERM` / `SIGINT` so jobs in flight finish before the process exits. Restart limits (max-jobs / max-runtime / max-memory) let a supervisor (systemd, Supervisord, K8s) recycle the process to release memory and pick up fresh code.

```php
use Solo\JobQueue\Worker;
use Solo\JobQueue\WorkerLimits;

$worker = new Worker(
    queue:          $queue,
    batchSize:      10,
    type:           'email',  // optional — only process this type
    sleepWhenEmpty: 1,        // seconds to sleep when no jobs are ready
    limits:         new WorkerLimits(
        maxJobs:     1000,    // restart after N jobs (0 = unlimited)
        maxRuntime:  3600,    // restart after N seconds (0 = unlimited)
        maxMemoryMb: 256,     // restart if memory exceeds N MB (0 = unlimited)
    ),
    logger:         $logger,
);

$worker->run(); // blocks until SIGTERM/SIGINT, stop() called, or a limit hit
```

If the `pcntl` extension is unavailable, signal handlers are silently skipped — exit relies on limits or `stop()`.

## Observability hooks

Implement `Solo\Contracts\JobQueue\JobQueueListener` to ship metrics, open tracing spans, or enrich your log context per job:

```php
use Solo\Contracts\JobQueue\JobQueueListener;

final class MetricsListener implements JobQueueListener
{
    public function onClaimed(int $jobId, string $jobClass): void
    {
        Metrics::increment('queue.claimed', tags: ['job' => $jobClass]);
    }
    public function onCompleted(int $jobId): void
    {
        Metrics::increment('queue.completed');
    }
    public function onFailed(int $jobId, Throwable|string $error, bool $permanent): void
    {
        Metrics::increment($permanent ? 'queue.dead' : 'queue.retried');
    }
    public function onReclaimed(int $requeued, int $permanentlyFailed): void
    {
        Metrics::gauge('queue.reclaimed', $requeued + $permanentlyFailed);
    }
}

$queue = new JobQueue(..., listener: new MetricsListener());
```

Listener exceptions propagate to the caller, so keep handlers cheap and non-throwing.

## Operational API

```php
// Inspect dead jobs without writing SQL
$failed = $queue->getFailedJobs(limit: 50, type: 'email');

// Manually re-queue a dead job (resets retry_count to 0, schedules now)
$queue->retry($jobId);

// Run reclaim on a separate cron when you set autoReclaim: false
$result = $queue->reclaimStuck();
// ['requeued' => 3, 'failed' => 1]
```

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
| `pushMany(JobInterface[] $jobs, ?string $type = null, ?DateTimeImmutable $scheduledAt = null, ?DateTimeImmutable $expiresAt = null): int` | Bulk-insert many jobs sharing the same type/schedule in a single statement. Returns the number of rows inserted. |
| `addJob(array $payload, ?DateTimeImmutable $scheduledAt = null, ?DateTimeImmutable $expiresAt = null, ?string $type = null): int` | Enqueue a raw payload (must contain `job_class`). |
| `processJobs(int $limit = 10, ?string $onlyType = null): int` | Reclaim stuck jobs (unless `autoReclaim = false`), then claim and run pending jobs. Returns how many were executed. |
| `reclaimStuck(): array{requeued: int, failed: int}` | Return stuck jobs to pending or mark them failed. Safe to call from a separate cron. |
| `getPendingJobs(int $limit = 10, ?string $onlyType = null): array` | Informational read of pending jobs. Does not lock rows. |
| `getFailedJobs(int $limit = 50, ?string $type = null): array` | Inspect permanently-failed jobs (most recent first). |
| `getStats(?string $type = null): array{pending: int, in_progress: int, completed: int, failed: int}` | Counts grouped by status, optionally filtered by `type`. All four keys always present. |
| `retry(int $jobId): bool` | Re-queue a failed job: status → pending, retry_count → 0, scheduled_at → now, error cleared. Returns true if a row was updated. |
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
