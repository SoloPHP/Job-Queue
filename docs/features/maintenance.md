# Maintenance and Monitoring

## maintain()

```php
$result = $queue->maintain(); // ['requeued' => 1, 'failed' => 0, 'expired' => 2]
```

Maintenance does two things:

1. It **recovers abandoned attempts**: up to 100 jobs whose lease ended, oldest first. Each is decided with its own saved policy and deadline, exactly like a [failed attempt](/features/retries#after-a-failure): requeued after its backoff, `failed` when its attempts are used up, or `expired` when its deadline passed. The error stored is `Worker lease expired`.
2. It **expires overdue jobs**: every pending job whose deadline has passed becomes `expired`, with the error `Job execution deadline passed`.

`requeued` and `failed` count recovered attempts; `expired` counts the recovered attempts past their deadline plus the overdue pending jobs. A larger backlog of abandoned attempts drains over the next runs.

Maintenance is safe from several processes at once: recovering an attempt is a conditional update on the token and the expired lease it was read with, so an attempt that was completed or renewed in the meantime is left alone, and no job is counted twice. An index on `(status, expires_at)` and one on `lease_until` keep both steps cheap on large tables.

Who runs it:

- the [worker](/features/worker#maintenance), at start and then every 60 seconds by default;
- `processJobs()`, once per call;
- or your own cron job, with `Worker(maintenanceInterval: null)`.

`processNext()` and `claim()` never run it.

## Statistics

```php
$queue->getStats();         // the whole table
$queue->getStats('import'); // one type
// ['pending' => 7, 'in_progress' => 1, 'completed' => 192, 'failed' => 0, 'expired' => 0]
```

All five statuses are always present. `getStats()` counts stored statuses: a pending job past its deadline is counted as pending until maintenance expires it, so call `maintain()` first when no worker is running and you need it reflected now. `pending` includes jobs scheduled for later.

## Inspecting Jobs

```php
$ready = $queue->getPendingJobs(10, 'email');    // ready jobs, in claim order; claims nothing
$failed = $queue->getFailedJobs(limit: 50, type: 'email'); // failed jobs, newest first

foreach ($failed as $record) {
    printf(
        "#%d %s (%d/%d attempts, finished %s): %s\n",
        $record->id,
        $record->name,
        $record->attempts,
        $record->maxAttempts,
        $record->finishedAt?->format(DATE_ATOM),
        $record->error,
    );
}
```

Both return a list of [`JobRecord`](/api/value-objects#jobrecord): ID, class name, type, status, attempts, attempt budget, error, payload, and the scheduled, deadline, creation and finish times as UTC `DateTimeImmutable` values. `getPendingJobs()` lists only jobs that are ready now, as a claim would pick them.

## Pruning History

Completed, failed and expired jobs stay in the table until you delete them:

```php
use Solo\JobQueue\JobStatus;

$queue->prune(new DateTimeImmutable('-7 days')); // completed jobs finished over a week ago
$queue->prune(new DateTimeImmutable('-30 days'), [JobStatus::Failed, JobStatus::Expired]);
```

`prune()` deletes jobs with the given statuses whose `finished_at` is before the cutoff, in batches of 1,000 rows so no single delete holds its locks for long, and returns how many it deleted. Only finished statuses are accepted: `pending` or `in_progress`, or an empty list, throws `InvalidArgumentException`. A daily cron job is enough; with `deleteOnSuccess: true` the queue deletes completed jobs at once instead of keeping them.

## Listener Events

Implement `Solo\Contracts\JobQueue\JobQueueListener` to ship metrics, open tracing spans or set log context per job:

```php
use Solo\Contracts\JobQueue\JobQueueListener;
use Throwable;

final class MetricsListener implements JobQueueListener
{
    public function onClaimed(int $jobId, string $jobClass): void { /* start a span */ }
    public function onCompleted(int $jobId): void { /* count a success */ }
    public function onFailed(int $jobId, Throwable|string $error, bool $permanent): void { /* count a failure */ }
    public function onReclaimed(int $requeuedCount, int $permanentlyFailedCount): void { /* count recoveries */ }
}

$queue = new JobQueue($storage, $codec, listener: new MetricsListener());
```

| Method | Called |
|---|---|
| `onClaimed()` | After a claimed job was decoded, before `handle()` runs |
| `onCompleted()` | After a completion was recorded |
| `onFailed()` | After a failed attempt was recorded; `permanent` is `true` when the job will not run again by itself, including expiry |
| `onReclaimed()` | Once per maintenance run that recovered attempts; the second count includes recovered attempts that expired |

Each `onClaimed()` is followed by `onCompleted()` or `onFailed()` for the same attempt, unless maintenance recovered the job first. A payload that cannot be decoded produces `onFailed()` without `onClaimed()`. A [manual claim](/features/processing#manual-claims) calls no `onClaimed()`; its `complete()` and `fail()` call theirs.

Keep listeners cheap and non-throwing: an exception from a listener propagates to the caller and is never turned into a failure of the job.

## Logging

Pass a PSR-3 logger to `JobQueue`. Throwables are passed under the PSR-3 `exception` key, so structured log handlers keep the trace.

| Message | Level | Context |
|---|---|---|
| `Job queued` | `info` | `id`, `name`, `type` |
| `Jobs queued` | `info` | `count`, `type` |
| `Running job` | `info` | `id`, `class` |
| `Job completed` | `info` | `id` |
| `Job failed, will retry` | `warning` | `id`, `attempt`, `next_run`, and `exception` or `error` |
| `Job permanently failed` | `error` | `id`, `attempt`, `next_run` (`null`), and `exception` or `error` |
| `Job expired after failure` | `error` | `id`, `attempt`, `next_run` (`null`), and `exception` or `error` |
| `Job ownership lost before completion` | `warning` | `id` |
| `Job ownership lost before failure` | `warning` | `id` |
| `Reclaimed stuck jobs` | `warning` | `requeued`, `failed`, `expired` |
| `Pending jobs expired` | `warning` | `count` |
| `Job re-queued` | `info` | `id` |
| `Pruned finished jobs` | `info` | `count` |
