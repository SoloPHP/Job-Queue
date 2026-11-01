# Worker

In `Solo\JobQueue\Worker`. See [Worker](/features/worker).

## Worker

The long-running loop. Final.

```php
public function __construct(
    JobProcessorInterface $queue,
    ?string $type = null,
    int $sleepWhenEmpty = 1,
    ?WorkerLimits $limits = null,
    ?LoggerInterface $logger = null,
    bool $handleSignals = true,
    ?int $maintenanceInterval = 60,
)
```

| Parameter | Type | Description |
|---|---|---|
| `$queue` | `Solo\Contracts\JobQueue\JobProcessorInterface` | Where jobs come from, such as a `JobQueue` |
| `$type` | `?string` | Only jobs of this type; `null` for every type |
| `$sleepWhenEmpty` | `int` | Seconds to sleep when no job is ready |
| `$limits` | `?WorkerLimits` | When to stop; none by default |
| `$logger` | `?Psr\Log\LoggerInterface` | For the worker's start and stop messages |
| `$handleSignals` | `bool` | Stop gracefully on SIGTERM and SIGINT when `pcntl` is loaded |
| `$maintenanceInterval` | `?int` | Seconds between `maintain()` runs; 0 before every job, `null` never |

**Throws:**
- `InvalidArgumentException` — a negative `$sleepWhenEmpty` or `$maintenanceInterval`

### run()

```php
public function run(): void
```

Runs jobs one at a time until stopped or a limit is reached. Restores the previous signal handlers when it returns or throws. Errors from the queue other than handler failures end it with that exception.

### stop()

```php
public function stop(): void
```

Asks the loop to stop after the current job. Safe to call from a signal handler or a listener.

---

## WorkerLimits

When a worker stops. Final and readonly; each limit is opt-in.

```php
public function __construct(
    int $maxJobs = 0,
    int $maxRuntime = 0,
    int $maxMemoryMb = 0,
)
```

| Property | Description |
|---|---|
| `maxJobs` | Jobs to run before stopping; 0 for no limit |
| `maxRuntime` | Seconds to run before stopping; 0 for no limit |
| `maxMemoryMb` | Allocated memory, in megabytes, at which to stop; 0 for no limit |

**Throws:**
- `InvalidArgumentException` — a negative limit

### unlimited()

```php
public static function unlimited(): self
```

No limits: only a signal or `stop()` ends the worker.

### reachedReason()

```php
public function reachedReason(int $totalProcessed, float $elapsedSeconds): ?string
```

The first limit reached — `max_jobs`, `max_runtime` or `max_memory` — or `null`. `$elapsedSeconds` is the time since the worker started, as measured by the caller.
