# Delivery and Errors

## At Least Once

Delivery is **at least once**, within the attempt budget and the deadline. A job can run more than once:

- a handler can finish its side effect and the worker die before the completion is recorded; another worker then runs the job again;
- a handler that runs longer than its [lease](/features/processing#lease-duration) can overlap with the next attempt;
- a failed attempt is retried, and it may have done part of its work.

Ownership tokens make sure a stale attempt cannot overwrite the queue state of the attempt that replaced it. They cannot undo or fence the side effects a handler has on other systems.

## Idempotent Handlers

Write handlers so that running one twice has the effect of running it once:

- use a stable business identifier, such as the order ID, and check whether its work is already done;
- let a unique constraint in your database reject the second insert;
- pass an idempotency key to external APIs that accept one, derived from the job's data.

The claim token identifies one attempt, so it changes between attempts and is not a deduplication key.

## What Counts as a Failure

Only an exception from [decoding](/features/jobs#when-decoding-fails) the job or from its `handle()` method fails the attempt. Errors from everything around the job propagate to the caller without touching the job:

- an error while recording the completion leaves the result unknown; the lease decides, and the job is recovered when it ends;
- an error from a logger or a listener after a successful `handle()` does not turn the success into a retry;
- a listener that throws in `onClaimed()`, before `handle()`, leaves the claimed attempt to be recovered when its lease ends.

Run workers under a supervisor that restarts them after such an error.

## Error Text

A failure is stored in the job's `error` column. A `Throwable` is stored as `Class: message @ file:line`; a string as it is. Before storing, the queue makes the text safe for every supported database: invalid UTF-8 is replaced, NUL bytes become the two characters `\0`, and text over 16 KB is cut at a character boundary and ends with `...`. The logger still receives the original exception or string.

## Time and Clocks

Every time the queue stores — schedule, deadline, lease, creation and finish — is UTC, whatever the PHP default timezone or the timezone of the dates you pass. The queue computes times in PHP, not in the database, so keep the clocks of the hosts that run workers synchronized: a worker whose clock is far ahead may consider other workers' leases ended.

A PSR-20 clock makes scheduling and lease tests deterministic:

```php
use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now = new DateTimeImmutable('2030-01-01 00:00:00 UTC'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }
}

$clock = new FrozenClock();
$queue = new JobQueue($storage, $codec, clock: $clock);

$queue->push($job, expiresAt: $clock->now()->modify('+1 minute'));
$clock->advance(120);
$queue->maintain(); // ['requeued' => 0, 'failed' => 0, 'expired' => 1]
```

The [worker](/features/worker#limits) measures its runtime limit and maintenance interval with a monotonic clock instead.
