# Retries and Deadlines

## Retry Policy

```php
use Solo\JobQueue\{JobQueue, RetryPolicy};

$queue = new JobQueue(
    $storage,
    $codec,
    retry: new RetryPolicy(maxAttempts: 5, baseDelay: 30, maxDelay: 1800),
);
```

| Option | Default | Effect |
|---|---|---|
| `maxAttempts` | `3` | Attempts in all, the first execution included; at least 1 |
| `baseDelay` | `60` | Seconds before the first retry; non-negative |
| `maxDelay` | `3600` | The cap on any delay; non-negative |

Two presets: `RetryPolicy::aggressive()` — 6 attempts, 5 seconds doubling to 5 minutes — and `RetryPolicy::lenient()` — 10 attempts, 5 minutes doubling to 2 hours.

The policy is **stored with each job** when it is enqueued, in `max_attempts`, `base_delay` and `max_delay`. Workers read the job's own policy, so a queue configured differently cannot shorten another job's attempt budget, and changing the policy affects only jobs enqueued afterwards. Different policies can share one table: build one `JobQueue` per policy over the same storage.

## Backoff

After a failed attempt the job waits `baseDelay`, then twice as long after each further failure, never more than `maxDelay`:

| Failed attempt | Default policy | `aggressive()` |
|---|---|---|
| 1st | retry in 60 s | retry in 5 s |
| 2nd | retry in 120 s | retry in 10 s |
| 3rd | `failed` | retry in 20 s |
| 6th | — | `failed` |

A zero `baseDelay` or `maxDelay` retries immediately.

## After a Failure

When an attempt fails — its handler threw, its payload could not be decoded, or maintenance found its lease ended — the queue decides the job's next state:

1. Its deadline has passed: `expired`.
2. It has used all its attempts: `failed`.
3. The retry would only be due at or after its deadline, so it could never run: `expired`.
4. Otherwise: `pending` again, due after the backoff.

`failed` and `expired` are terminal and set `finished_at`. The error of the last attempt is stored in `error`. For a failed attempt the listener's `onFailed()` receives `permanent: true` when the job ends `failed` or `expired`, and `false` for a retry; attempts recovered by maintenance are reported by `onReclaimed()` instead.

## Deadlines

```php
$queue->push($job, 'email', expiresAt: new DateTimeImmutable('+1 day'));
```

A job is never claimed at or after its `expiresAt`. A pending job that missed its deadline is moved to `expired` by [maintenance](/features/maintenance#maintain), with the error `Job execution deadline passed`; until then it still counts as pending in the statistics.

A job already running when its deadline passes is not interrupted. If it succeeds, it is completed; if it fails, the failure is terminal, because a retry could not run.

## Retrying by Hand

```php
$queue->retry($id);                                              // a failed job
$queue->retry($id, expiresAt: new DateTimeImmutable('+1 day')); // a failed or expired job, with a new deadline
```

`retry()` makes a job `pending` and due now, clears its error and finish time, and resets its attempts to zero, so it gets its full budget again under the policy saved with it. It returns `true` when it requeued the job and `false` otherwise:

- without `expiresAt` it requeues only a `failed` job, and only when its deadline, if any, has not passed: a pending job with a deadline in the past could never run;
- with `expiresAt` it also requeues an `expired` job and sets the new deadline, which must be in the future;
- a `pending`, `in_progress` or `completed` job is never touched.

A requeued job is logged as `Job re-queued` at `info`.
