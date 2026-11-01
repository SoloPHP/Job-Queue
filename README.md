# JobQueue

A database-backed PHP queue with atomic claims, expiring ownership tokens, persisted retry policies, and a small worker daemon.

Requires PHP **8.3+** with `mbstring`, Doctrine DBAL 4, and a PDO driver for SQLite 3.35+, MySQL 8+, MariaDB 10.6+, or a PostgreSQL version supported by your DBAL release. `pcntl` is optional and enables graceful shutdown on Unix.

This is the **v2 API**. Upgrading an existing installation requires the schema and API changes in [UPGRADING.md](UPGRADING.md).

## Install and configure

```bash
composer require solophp/job-queue
```

```php
use Doctrine\DBAL\DriverManager;
use Solo\JobQueue\{JobQueue, RetryPolicy};
use Solo\JobQueue\Codec\ContainerJobCodec;
use Solo\JobQueue\Storage\{DbalJobStorage, Schema};

$connection = DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => 'localhost',
    'dbname' => 'app',
    'user' => 'app',
    'password' => 'secret',
]);

Schema::install($connection); // New installations; existing v1 tables need Schema::migrate().

$queue = new JobQueue(
    storage: new DbalJobStorage($connection),
    codec: new ContainerJobCodec($container), // PSR-11 container
    retry: new RetryPolicy(maxAttempts: 3, baseDelay: 60, maxDelay: 3600),
    leaseDuration: 600,
    // logger: $logger,                    // Optional PSR-3 logger
    // listener: $listener,                // Optional JobQueueListener
    // deleteOnSuccess: true,              // Default false: retain completed rows
);
```

Creating the storage or the queue does not open a database connection; the first query does.

Retry settings are stored **with each job when it is enqueued**. Consumer configuration cannot shorten another job's lease or change its attempt budget. `maxAttempts` includes the first execution. Delays after failed attempts are `baseDelay`, `2 * baseDelay`, etc., capped by `maxDelay`. Lease timeout is separate from retry policy.

`JobQueue` implements two narrow interfaces from `solophp/contracts`. Type-hint `Solo\Contracts\JobQueue\JobProducerInterface` (`push()`/`pushMany()`) in services that only enqueue: unlike the final `JobQueue`, it is easy to mock, and integrations need only the contracts package. `JobProcessorInterface` covers execution and is what `Worker` uses.

The codec converts jobs to JSON and back. `ContainerJobCodec` restores dependencies through a PSR-11 container, so it needs one in producers too. For custom serialization and construction, implement the two-method `JobCodecInterface` and pass it as `codec`. Its `encode()` must return valid JSON for the database JSON/JSONB column; the queue validates this before insertion.

## Package layout

| Namespace | Contents |
| --- | --- |
| `Solo\JobQueue` | `JobQueue` and its value types: `ClaimedJob`, `JobRecord`, `JobStatus`, `RetryPolicy` |
| `Solo\JobQueue\Codec` | `JobCodecInterface`, `ContainerJobCodec`, `PersistableJobInterface` |
| `Solo\JobQueue\Storage` | `DbalJobStorage` and `Schema` for MySQL, MariaDB, PostgreSQL, and SQLite |
| `Solo\JobQueue\Worker` | The `Worker` daemon and its `WorkerLimits` |
| `Solo\Contracts\JobQueue` | `JobInterface`, `JobProducerInterface`, `JobProcessorInterface`, `JobQueueListener` (package `solophp/contracts`) |

## Define a job

```php
use Psr\Container\ContainerInterface;
use Solo\JobQueue\Codec\PersistableJobInterface;

final class SendEmailJob implements PersistableJobInterface
{
    public function __construct(
        private readonly string $to,
        private readonly string $subject,
        private readonly string $body,
        private readonly ?Mailer $mailer = null,
    ) {}

    public static function createFromContainer(ContainerInterface $container, array $data): self
    {
        return new self($data['to'], $data['subject'], $data['body'], $container->get(Mailer::class));
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

`ContainerJobCodec` accepts only `PersistableJobInterface` jobs, both when enqueueing and when restoring them; the stored class name must implement it too. Job classes written for v1 usually need only `implements PersistableJobInterface`; [UPGRADING.md](UPGRADING.md) lists the required signatures.

The codec keeps the v1 `{job_class, job_data}` JSON format, so queued v1 payloads remain readable; a payload without `job_data` restores with an empty array. Keep old job classes loadable until their queued jobs have drained. Applications needing stable aliases or payload versions can provide their own codec without changing storage.

## Enqueue and inspect

```php
use Solo\JobQueue\JobStatus;

$id = $queue->push(new SendEmailJob('user@example.com', 'Welcome', 'Hi!'), 'email');

$queue->push($job, 'email',
    scheduledAt: new DateTimeImmutable('+1 hour'),
    expiresAt: new DateTimeImmutable('+1 day'),
);

$count = $queue->pushMany($jobs, 'import');
$ready = $queue->getPendingJobs(10, 'email'); // list<JobRecord>; informational, claims nothing.
$stats = $queue->getStats('import');
// ['pending' => 7, 'in_progress' => 1, 'completed' => 192, 'failed' => 0, 'expired' => 0]

foreach ($queue->getFailedJobs(limit: 50, type: 'email') as $record) {
    printf("#%d %s: %s (%s)\n", $record->id, $record->name, $record->error, $record->finishedAt?->format(DATE_ATOM));
}
$queue->retry($id); // Failed jobs only; resets attempts. Preserves the saved retry policy.
$queue->retry($id, expiresAt: new DateTimeImmutable('+1 day')); // Also permits expired jobs.

$queue->prune(new DateTimeImmutable('-7 days')); // Completed jobs that finished over a week ago.
$queue->prune(new DateTimeImmutable('-30 days'), [JobStatus::Failed, JobStatus::Expired]);
```

`JobRecord` exposes `id`, `name`, `type`, `status` (a `JobStatus`), `attempts`, `maxAttempts`, `error`, `payload`, and UTC `scheduledAt`, `expiresAt`, `createdAt` and `finishedAt`. `finishedAt` is set when a job completes, fails permanently, or expires, and is cleared by `retry()`. `prune()` deletes finished jobs in batches of 1,000 rows.

`pushMany()` writes chunks of up to 1,000 rows. Each chunk is atomic; a later failure does not roll back earlier chunks. Payload size remains subject to the database's packet/statement limits. Both `push()` and `pushMany()` can participate in a producer transaction, so application changes and job insertion can commit together.

An expired deadline prevents a new claim. Maintenance changes pending jobs past their deadline to terminal `expired`; they no longer count as pending. A failed attempt whose retry would only be due at or after the deadline expires immediately. A failed/expired job past its deadline cannot be retried without an explicit future deadline. An already-claimed job is not interrupted by `expiresAt`; success can still be acknowledged, but failure after expiry is terminal.

`getStats()` counts stored statuses; call `maintain()` if no worker is running and you need expired pending rows reflected immediately. Completed and failed history is retained until you `prune()` it; `deleteOnSuccess` removes successful rows right away. Use distinct type values for independent batches if tracking progress this way.

## Process jobs

```php
$queue->processJobs(10);          // Maintenance, then up to 10 attempts of any type.
$queue->processJobs(10, 'email'); // Only execute email jobs.
$queue->processNext('email');     // One attempt, no maintenance; false when nothing is ready.
```

`processJobs()` runs maintenance once, then claims **one job immediately before execution**, up to the requested limit. Other jobs remain available to other workers. Claiming increments `attempts` and stores a random token plus `lease_until`. Completion, failure, and deletion require the current token; renewal also requires an unexpired lease.

PostgreSQL and SQLite claim with one atomic `UPDATE … RETURNING` statement (selecting the row with `FOR UPDATE SKIP LOCKED` on PostgreSQL). MySQL/MariaDB use a short `SELECT … FOR UPDATE SKIP LOCKED` transaction that always runs at READ COMMITTED: under REPEATABLE READ, gap locks on the status index make concurrent claims deadlock. With binary logging enabled, the server must use `binlog_format` ROW or MIXED (the MySQL and MariaDB defaults); `STATEMENT` rejects InnoDB writes at READ COMMITTED. Consumer statements aborted by a deadlock, a lock wait timeout, or a busy SQLite database are retried up to five times. Configure SQLite `busy_timeout` for your workload; persistent database errors propagate to the caller.

Consumer operations require an autocommit connection with no active transaction. Handlers execute outside the claim transaction. Use a dedicated worker connection if your application's connection may be inside a transaction. Handlers must finish any transactions they start before returning.

The default lease is 600 seconds. Choose a duration longer than the longest uninterrupted handler operation, including external service timeouts. The standard worker does not run a background heartbeat or forcibly stop a handler. A handler only starts while its lease is valid: if decoding or an `onClaimed` listener used the lease up, the attempt fails with "Lease expired before execution started" instead. For cooperative long-running work, the manual claim API supports renewal:

```php
$claim = $queue->claim('import');
if ($claim !== null) {
    // A custom execution loop can call $claim = $queue->renew($claim) between bounded work units.
    // Stop working when renew() returns null: the lease ended or another attempt took over.
    try {
        $codec->decode($claim->payload)->handle();
    } catch (Throwable $error) {
        $queue->fail($claim, $error);
        throw $error;
    }
    $queue->complete($claim); // false once maintenance has reclaimed the job.
}
```

`renew()` returns a copy of the claim with the extended `leaseUntil`; the token stays the same. It never shortens or revives an ended lease. `complete()` and `fail()` are accepted while the attempt still owns the job, even after its lease ended, until maintenance reclaims it: a matching token proves that no other worker took over. Manual `claim()` does not run maintenance or emit listener events; `complete()` and `fail()` emit theirs.

## Delivery and error handling

Delivery is **at least once**, subject to the attempt budget and expiry. Make handlers idempotent: use stable business-operation identifiers, unique database constraints, and external API idempotency keys. A claim token identifies one attempt and is unsuitable as a deduplication key across retries.

A worker can complete an external side effect and crash before acknowledgement. Another worker can then repeat the job. Ownership tokens prevent a stale worker from overwriting the new worker's queue state; they cannot undo or fence arbitrary external side effects. A handler exceeding its lease can still overlap with a later attempt.

Only decoding and handler errors trigger `fail()`. Errors from acknowledgement, loggers, and listeners propagate without converting a successful job into a retry. A persistence error after the side effect leaves the result uncertain; recovery uses the lease. Listener failures before execution can leave the claimed attempt for recovery. Supervisors should restart failed workers.

Error text is stored as valid UTF-8 without NUL bytes and truncated to 16 KB, so any exception message can be recorded on every supported database.

All application-supplied dates are normalized to UTC. Keep clocks synchronized between worker hosts. `clock: $clock` accepts a PSR-20 `ClockInterface` for deterministic scheduling and lease tests. Worker runtime limits and the maintenance interval use monotonic time.

## Worker daemon

```php
use Solo\JobQueue\Worker\{Worker, WorkerLimits};

$worker = new Worker(
    queue: $queue,
    type: 'email',
    sleepWhenEmpty: 1,
    limits: new WorkerLimits(maxJobs: 1000, maxRuntime: 3600, maxMemoryMb: 256),
    maintenanceInterval: 60, // Seconds; null when a cron calls $queue->maintain().
    // logger: $logger,
    // handleSignals: false, // Let an embedding application manage signals itself.
);
$worker->run();
```

The worker runs maintenance at start and then every `maintenanceInterval` seconds (0 means before every job), and executes one job at a time with `processNext()`. It checks stop requests, job count, memory, and runtime before each claim. SIGTERM/SIGINT finish the current job and then stop. Time and memory limits do not interrupt a running handler. Previous signal handlers and async mode are restored when `run()` returns or throws. Without `pcntl`, use limits or `stop()`.

Limits are non-negative; zero means unlimited. `sleepWhenEmpty` must be non-negative; keep it at least one second in production to avoid a busy loop. Run the worker under systemd, Supervisor, or another process supervisor.

## Maintenance and observability

```php
$result = $queue->maintain(); // ['requeued' => N, 'failed' => N, 'expired' => N]
```

Maintenance recovers up to 100 attempts whose lease expired, applying each job's saved attempt budget, backoff, and deadline, and moves pending jobs past their deadline to `expired`. `requeued` and `failed` count recovered attempts; `expired` counts recovered attempts past their deadline plus overdue pending jobs. Repeated runs drain larger backlogs, and concurrent runs from several workers are safe. Indexes keep maintenance cheap with large backlogs.

Implement `Solo\Contracts\JobQueue\JobQueueListener` for `onClaimed`, `onCompleted`, `onFailed`, and `onReclaimed`. Each `onClaimed` is followed by `onCompleted` or `onFailed` for that attempt, unless maintenance reclaimed the job first; `onReclaimed` then reports aggregate counts, and its second argument includes recovered attempts that expired. A payload that cannot be decoded produces `onFailed` without `onClaimed`. The `permanent` flag means no automatic retry, including expiry. Keep observers cheap and non-throwing. Throwable failures reach PSR-3 loggers under the `exception` key; maintenance logs reclaimed and expired jobs as warnings.

`JobStorageInterface` is internal and may change in any 2.x release: construct `DbalJobStorage` and pass it to `JobQueue`. The deprecated `Solo\Contracts\JobQueue\JobQueueInterface` is not implemented; adapters using it need updating to `JobProducerInterface` and `JobProcessorInterface` from the same package.

## Testing

```bash
composer install
composer test # PSR-12, PHPStan, PHPUnit
```

The default suite uses SQLite, including file-backed two-connection contention when `pcntl` is available and a stress test in which four worker processes drain one queue and every job must run exactly once. CI also runs MySQL, MariaDB, and PostgreSQL tests on PHP 8.3/8.4/8.5, including legacy migrations, stale acknowledgements, row-lock skipping, concurrent workers, retry isolation, observer errors, and signal handling.

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

**Use a dedicated test database:** the integration suite drops and recreates its `jobs` table. The default database name is `queue_test`; override with `QUEUE_TEST_DB`. PostgreSQL uses driver `pdo_pgsql` and its assigned port. The Compose services bind only to localhost and keep database data in temporary filesystems.

MIT — see [LICENSE](LICENSE).
