# Processing and Leases

```php
$queue->processJobs(10);          // maintenance, then up to 10 jobs of any type
$queue->processJobs(10, 'email'); // only email jobs
$queue->processNext('email');     // one job, no maintenance; false when none is ready
```

`processJobs()` runs [maintenance](/features/maintenance#maintain) once, then claims and runs one job at a time until it has run `$limit` jobs or none is ready. It returns how many attempts it ran, failed ones included. Each job is claimed **immediately before it runs**, so the jobs after it stay available to other workers. A limit of zero does nothing; a negative one throws `InvalidArgumentException`.

`processNext()` claims and runs a single job without maintenance. The [worker](/features/worker) is built on it.

## Claims

A claim is one **attempt**. Claiming a ready job:

- moves it from `pending` to `in_progress` and counts the attempt in `attempts`;
- stores a random **token**, which only this attempt knows;
- sets a **lease**: `lease_until` = now + `leaseDuration` seconds, 600 by default.

The token is what acknowledges the attempt. Completing, failing and renewing a job are conditional updates on the job's ID **and** token, so an attempt that lost its job — because maintenance recovered it and another worker claimed it — cannot overwrite the new attempt's state.

A job is ready when it is `pending`, `scheduled_at` has come, its deadline, if any, has not passed, and it has attempts left.

## Running a Claimed Job

`processJobs()` and `processNext()` run each claim in this order:

1. The codec [decodes](/features/jobs#when-decoding-fails) the payload. If that fails, the attempt fails with the exception.
2. `Running job` is logged and the listener's `onClaimed()` is called.
3. If the lease has already ended — a slow decode or listener used it up — the attempt fails with `Lease expired before execution started`, and `handle()` is not called: maintenance may already have given the job to another worker.
4. `handle()` runs, outside any transaction.
5. On success the attempt is completed; on an exception it fails, and the [retry policy](/features/retries) decides what happens next.

Only exceptions from decoding and from `handle()` fail an attempt. See [What Counts as a Failure](/features/delivery#what-counts-as-a-failure).

## Lease Duration

Choose `leaseDuration` longer than the longest uninterrupted run of a handler, including the timeouts of the services it calls. The standard worker sends no heartbeat and never interrupts a handler: when a lease ends while the handler still runs, maintenance considers the attempt abandoned and gives the job to the next worker, and the two attempts overlap.

## When a Worker Dies

A worker killed in the middle of a job leaves it `in_progress` with a lease that will end. Once it has, [maintenance](/features/maintenance#maintain) recovers the attempt with the job's own policy: the job is requeued after its backoff, or marked `failed` if its attempts are used up, or `expired` if its deadline passed. The killed attempt counts as one of the attempts.

## Late Acknowledgements

An attempt can still be completed or failed after its lease ended, as long as maintenance has not recovered the job: a matching token proves that no other worker took over. `complete()` and `fail()` return `false` once the job has been recovered, change nothing, and log `Job ownership lost before completion` or `before failure` as a warning.

## Manual Claims

For your own execution loop, claim a job and acknowledge it yourself:

```php
$claim = $queue->claim('import');
if ($claim !== null) {
    try {
        $codec->decode($claim->payload)->handle();
    } catch (Throwable $error) {
        $queue->fail($claim, $error);
        throw $error;
    }
    $queue->complete($claim); // false once maintenance has recovered the job
}
```

`claim()` returns a [`ClaimedJob`](/api/value-objects#claimedjob): the job's ID, class name, payload, attempt number, token, lease end, retry policy and deadline. It runs no maintenance and calls no listener method; `complete()` and `fail()` call theirs.

### Renewing a Lease

Long work split into bounded steps can extend its lease between them:

```php
foreach ($chunks as $chunk) {
    $claim = $queue->renew($claim);
    if ($claim === null) {
        return; // the lease ended or another attempt owns the job: stop working on it
    }
    $importer->import($chunk);
}
$queue->complete($claim);
```

`renew()` sets the lease to now + `leaseDuration` and returns the claim with its new `leaseUntil`; the token stays the same. It never shortens a lease and never revives one that has ended.

## How Claims Work on Each Database

- **PostgreSQL** — one `UPDATE … RETURNING` statement, selecting the row with `FOR UPDATE SKIP LOCKED`, so concurrent workers skip each other's rows instead of waiting.
- **SQLite** — the same single statement; SQLite's write lock serializes claims.
- **MySQL and MariaDB** — a short transaction: `SELECT … FOR UPDATE SKIP LOCKED`, then an `UPDATE` of that row. It always runs at READ COMMITTED, because under REPEATABLE READ the gap locks on the status index make concurrent claims deadlock. With binary logging, the server must use `binlog_format` `ROW` or `MIXED`.

Statements of consumer operations that the database aborts for a deadlock, a lock wait timeout or a busy SQLite database are run again, up to five tries in all, with a short pause between them. A token-guarded transition cannot apply twice, so repeating one is safe. Other database errors propagate to the caller.

## Transactions

Consumer operations — claiming, acknowledging, renewing, maintenance and pruning — require a connection in autocommit mode with no active transaction, and throw `RuntimeException` otherwise. A claim must commit before the handler runs, or other workers could not see it.

- Give workers a dedicated connection if your application's connection may be inside a transaction.
- Handlers run outside the claim transaction. A handler that starts a transaction must commit or roll it back before it returns.
- Producers may still [enqueue inside a transaction](/features/enqueueing#in-a-transaction).
