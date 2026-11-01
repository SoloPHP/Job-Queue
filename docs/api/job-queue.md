# JobQueue

The queue. Final and readonly; implements `Solo\Contracts\JobQueue\JobProducerInterface` and `JobProcessorInterface`. It decides every next state of a job; the storage applies it as a conditional update.

```php
use Solo\JobQueue\JobQueue;

$queue = new JobQueue($storage, $codec);
```

## Constructor

```php
public function __construct(
    JobStorageInterface $storage,
    JobCodecInterface $codec,
    ?RetryPolicy $retry = null,
    int $leaseDuration = 600,
    bool $deleteOnSuccess = false,
    ?LoggerInterface $logger = null,
    ?JobQueueListener $listener = null,
    ?ClockInterface $clock = null,
)
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$storage` | `JobStorageInterface` | A [`DbalJobStorage`](/api/storage#dbaljobstorage); the interface is internal |
| `$codec` | `JobCodecInterface` | Turns jobs into JSON and back: see [Codec](/api/codec) |
| `$retry` | `?RetryPolicy` | Stored with each job this queue enqueues; `new RetryPolicy()` by default |
| `$leaseDuration` | `int` | Seconds an attempt owns its job |
| `$deleteOnSuccess` | `bool` | Delete a completed job instead of keeping it |
| `$logger` | `?Psr\Log\LoggerInterface` | Receives the [messages](/features/maintenance#logging) of the queue |
| `$listener` | `?JobQueueListener` | Receives the [events](/features/maintenance#listener-events) of each attempt |
| `$clock` | `?Psr\Clock\ClockInterface` | The time source; the system clock in UTC by default |

**Throws:**
- `InvalidArgumentException` — `$leaseDuration` is less than 1

---

## push()

```php
public function push(
    JobInterface $job,
    ?string $type = null,
    ?DateTimeImmutable $scheduledAt = null,
    ?DateTimeImmutable $expiresAt = null,
): int
```

Stores one job and returns its ID. `$type` defaults to `default`, `$scheduledAt` to now; `$expiresAt` is the [deadline](/features/retries#deadlines). May run inside a transaction. See [Enqueueing](/features/enqueueing).

**Throws:**
- `InvalidArgumentException` — the codec returned invalid JSON, or rejected the job
- `JsonException` — the codec could not encode the job's data
- `Doctrine\DBAL\Exception` — the insert failed

---

## pushMany()

```php
public function pushMany(
    array $jobs,
    ?string $type = null,
    ?DateTimeImmutable $scheduledAt = null,
    ?DateTimeImmutable $expiresAt = null,
): int
```

Stores a `list<JobInterface>` sharing one type, schedule and deadline, in atomic chunks of 1,000, and returns how many it stored. A failing chunk keeps the earlier ones. Throws like `push()`.

---

## processJobs()

```php
public function processJobs(int $limit = 10, ?string $type = null): int
```

Runs [maintenance](#maintain) once, then claims and runs up to `$limit` jobs one at a time, of `$type` only if given. Returns the number of attempts run, failed ones included. A limit of zero does nothing.

**Throws:**
- `InvalidArgumentException` — a negative `$limit`
- `RuntimeException` — the connection is inside a transaction or not in autocommit mode
- Exceptions from the database, the logger and the listener propagate; handler exceptions do not

---

## processNext()

```php
public function processNext(?string $type = null): bool
```

Claims and runs one job, without maintenance. Returns `false` when no job was ready. Throws like `processJobs()`.

---

## maintain()

```php
public function maintain(): array
```

Recovers up to 100 attempts whose lease ended, using each job's policy and deadline, and expires pending jobs past their deadline. Returns `array{requeued: int, failed: int, expired: int}`. Safe to run from several processes at once. See [Maintenance](/features/maintenance#maintain).

---

## claim()

```php
public function claim(?string $type = null): ?ClaimedJob
```

Claims one ready job for your own execution and returns its [`ClaimedJob`](/api/value-objects#claimedjob), or `null` when none is ready. Runs no maintenance and calls no listener method. See [Manual Claims](/features/processing#manual-claims).

**Throws:**
- `RuntimeException` — the connection is inside a transaction or not in autocommit mode

---

## complete()

```php
public function complete(ClaimedJob $job): bool
```

Marks the attempt's job completed, or deletes it with `deleteOnSuccess`, while the attempt still owns it — even after its lease ended, until maintenance recovers it. Returns `false`, changes nothing and logs a warning when the attempt no longer owns the job. Calls `onCompleted()` on success.

---

## fail()

```php
public function fail(ClaimedJob $job, Throwable|string $error): bool
```

Records a failed attempt under the same ownership rule as `complete()`. The job's saved [policy and deadline](/features/retries#after-a-failure) decide whether it is retried, `failed` or `expired`. Calls `onFailed()` on success.

---

## renew()

```php
public function renew(ClaimedJob $job): ?ClaimedJob
```

Extends the attempt's lease to now plus `leaseDuration` and returns the claim with its new `leaseUntil`. Returns `null` once the lease has ended or another attempt owns the job. Never shortens a lease.

---

## retry()

```php
public function retry(int $jobId, ?DateTimeImmutable $expiresAt = null): bool
```

Requeues a `failed` job with its attempts reset; with `$expiresAt`, also an `expired` one, and sets the new deadline. Returns `false` when the job is not eligible or `$expiresAt` is not in the future. See [Retrying by Hand](/features/retries#retrying-by-hand).

---

## getPendingJobs()

```php
public function getPendingJobs(int $limit = 10, ?string $type = null): array
```

Returns a `list<JobRecord>` of the jobs ready now, in claim order. Claims nothing.

---

## getFailedJobs()

```php
public function getFailedJobs(int $limit = 50, ?string $type = null): array
```

Returns a `list<JobRecord>` of `failed` jobs, newest first.

---

## getStats()

```php
public function getStats(?string $type = null): array
```

Returns `array{pending: int, in_progress: int, completed: int, failed: int, expired: int}`, counting the whole table or one type.

---

## prune()

```php
public function prune(DateTimeImmutable $finishedBefore, array $statuses = [JobStatus::Completed]): int
```

Deletes jobs with the given finished statuses whose `finished_at` is before the cutoff, in batches of 1,000, and returns how many it deleted.

**Throws:**
- `InvalidArgumentException` — an empty list, or a status that is not `Completed`, `Failed` or `Expired`
- `RuntimeException` — the connection is inside a transaction or not in autocommit mode
