# Worker

`Worker` is a long-running loop around a [`JobProcessorInterface`](/guide/installation#as-a-service) such as `JobQueue`:

```php
use Solo\JobQueue\Worker\{Worker, WorkerLimits};

$worker = new Worker(
    queue: $queue,
    type: 'email',
    sleepWhenEmpty: 1,
    limits: new WorkerLimits(maxJobs: 1000, maxRuntime: 3600, maxMemoryMb: 256),
    logger: $logger,
    maintenanceInterval: 60,
);
$worker->run();
```

| Option | Default | Effect |
|---|---|---|
| `queue` | — | The `JobProcessorInterface` to run jobs from |
| `type` | `null` | Run only jobs of this type; `null` runs every type |
| `sleepWhenEmpty` | `1` | Seconds to sleep when no job is ready; non-negative |
| `limits` | none | When to stop: see [Limits](#limits) |
| `logger` | `null` | A PSR-3 logger for the worker's own messages |
| `handleSignals` | `true` | Stop gracefully on SIGTERM and SIGINT when `pcntl` is loaded |
| `maintenanceInterval` | `60` | Seconds between maintenance runs: see [Maintenance](#maintenance) |

Pass the options by name: the second constructor parameter is `type`.

## The Loop

Until it is stopped, the worker repeats:

1. Stop if a limit has been reached.
2. Run maintenance if it is due.
3. Claim and run one job with `processNext()`.
4. If no job was ready, sleep for `sleepWhenEmpty` seconds, shortened to the runtime left under `maxRuntime`.

Keep `sleepWhenEmpty` at one second or more in production: zero turns an empty queue into a busy loop of queries.

Exceptions do not stop at the worker. A failing job is recorded by the queue and the loop goes on, but an error from the database, a logger or a listener ends `run()` with that exception. Let the supervisor restart the process.

## Limits

```php
new WorkerLimits(maxJobs: 1000, maxRuntime: 3600, maxMemoryMb: 256);
```

| Limit | Stops after |
|---|---|
| `maxJobs` | this many jobs were run |
| `maxRuntime` | this many seconds since `run()` started |
| `maxMemoryMb` | PHP's allocated memory reached this many megabytes |

Each limit is non-negative, and zero, the default, means unlimited; `WorkerLimits::unlimited()` sets none. Limits are checked between jobs and never interrupt a running handler. Runtime is measured with a monotonic clock, so a change of the system time cannot shorten or extend it.

A worker that exits at a limit is how PHP returns its memory and how new code gets deployed: run the worker under a supervisor that starts it again.

## Stopping

With `pcntl` loaded and `handleSignals` on, SIGTERM and SIGINT ask the worker to stop: the current job finishes, then `run()` returns. The previous signal handlers and async-signal mode are restored when `run()` returns or throws. Turn `handleSignals` off when the embedding application manages signals itself.

`$worker->stop()` asks the same from code, for example from a listener or a test. Without `pcntl`, rely on limits or `stop()`.

## Maintenance

The worker runs [`maintain()`](/features/maintenance#maintain) when it starts and then every `maintenanceInterval` seconds, measured with a monotonic clock:

- `0` runs maintenance before every job;
- `null` never runs it: use that when a cron job calls `$queue->maintain()` instead.

Maintenance is safe to run from many workers at once, but one run a minute is enough for most queues.

## Several Workers

Run as many workers as you like against one table. Each claim takes a different job, and maintenance recovers the jobs of workers that died. A worker per type keeps slow jobs from holding up urgent ones.

## Under Supervisor

```ini
[program:jobs-email]
command=php /srv/app/bin/worker.php
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autorestart=true
stopsignal=TERM
stopwaitsecs=660
```

Give `stopwaitsecs`, or systemd's `TimeoutStopSec`, more time than the longest job takes, so a deploy lets the current job finish instead of killing it.

## Logging

| Message | Level | Context |
|---|---|---|
| `Worker started` | `info` | `type`, `max_jobs`, `max_runtime`, `max_memory`, `maintenance_interval` |
| `Worker stopping` | `info` | `reason` (`max_jobs`, `max_runtime` or `max_memory`), `processed` |
| `Worker stopped` | `info` | `processed`, `runtime_sec` |

The jobs themselves are logged by the queue, with the logger given to `JobQueue`.
