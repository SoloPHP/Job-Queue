# Value Objects

All are final and readonly, in `Solo\JobQueue`. Their dates are UTC `DateTimeImmutable` values.

## ClaimedJob

One attempt, returned by [`claim()`](/api/job-queue#claim) and [`renew()`](/api/job-queue#renew). The token is what acknowledges it: a job ID alone cannot.

| Property | Type | Description |
|---|---|---|
| `id` | `int` | The job's ID |
| `name` | `string` | The job's class name |
| `payload` | `string` | The codec's JSON, for [`decode()`](/api/codec#jobcodecinterface) |
| `attempt` | `int` | This attempt's number, from 1 |
| `token` | `string` | The random token of this attempt |
| `leaseUntil` | `DateTimeImmutable` | When this attempt's lease ends |
| `retryPolicy` | `RetryPolicy` | The policy stored with the job |
| `expiresAt` | `?DateTimeImmutable` | The job's deadline |

```php
public function withLeaseUntil(DateTimeImmutable $leaseUntil): self
```

A copy with another lease end; `renew()` returns one.

---

## JobRecord

A snapshot of a stored job, returned by [`getPendingJobs()`](/api/job-queue#getpendingjobs) and [`getFailedJobs()`](/api/job-queue#getfailedjobs).

| Property | Type | Description |
|---|---|---|
| `id` | `int` | The job's ID |
| `name` | `string` | The job's class name |
| `type` | `string` | Its type |
| `status` | `JobStatus` | Its status |
| `attempts` | `int` | Attempts started |
| `maxAttempts` | `int` | Its attempt budget |
| `error` | `?string` | The last attempt's error |
| `payload` | `string` | The codec's JSON |
| `scheduledAt` | `DateTimeImmutable` | When it may run next |
| `expiresAt` | `?DateTimeImmutable` | Its deadline |
| `createdAt` | `DateTimeImmutable` | When it was enqueued |
| `finishedAt` | `?DateTimeImmutable` | When it completed, failed or expired |

---

## JobStatus

A string-backed enum: the stored `status` value and a key of [`getStats()`](/api/job-queue#getstats).

| Case | Value |
|---|---|
| `JobStatus::Pending` | `pending` |
| `JobStatus::InProgress` | `in_progress` |
| `JobStatus::Completed` | `completed` |
| `JobStatus::Failed` | `failed` |
| `JobStatus::Expired` | `expired` |

`Completed`, `Failed` and `Expired` are finished and can be [pruned](/api/job-queue#prune).

---

## RetryPolicy

The retry settings stored with each job. See [Retries and Deadlines](/features/retries).

```php
public function __construct(
    int $maxAttempts = 3,
    int $baseDelay = 60,
    int $maxDelay = 3600,
)
```

| Property | Description |
|---|---|
| `maxAttempts` | Attempts in all, the first included |
| `baseDelay` | Seconds before the first retry |
| `maxDelay` | The cap on any delay, in seconds |

**Throws:**
- `InvalidArgumentException` — `$maxAttempts` below 1, or a negative delay

### aggressive()

```php
public static function aggressive(): self
```

6 attempts; delays from 5 seconds up to 5 minutes.

### lenient()

```php
public static function lenient(): self
```

10 attempts; delays from 5 minutes up to 2 hours.

### backoffSeconds()

```php
public function backoffSeconds(int $attempt): int
```

The delay after failed attempt `$attempt`, from 1: `baseDelay`, doubled for each further attempt, capped at `maxDelay`; 0 when either delay is 0.

**Throws:**
- `InvalidArgumentException` — `$attempt` below 1
