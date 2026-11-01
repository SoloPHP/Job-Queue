# Upgrading from v1 to v2

v2 changes the schema and public execution API. Upgrade producers, workers, and integrations together. PHP 8.3 remains the minimum; the `mbstring` extension and `solophp/contracts` 1.9 are now required.

## Deployment and schema

1. Stop all producers, workers, and maintenance processes using the table. Let active jobs finish where possible. Back up the table.
2. Deploy v2 code while the queue remains stopped. Job classes must already implement `Solo\JobQueue\Codec\PersistableJobInterface` (see below). Use an autocommit connection with no active transaction.
3. Run the explicit migration once, specifying the policy for existing jobs:

   ```php
   use Solo\JobQueue\RetryPolicy;
   use Solo\JobQueue\Storage\Schema;

   Schema::migrate(
       $connection,
       new RetryPolicy(maxAttempts: 3, baseDelay: 60, maxDelay: 3600),
       table: 'jobs',
   );
   ```

4. Verify counts and resume the updated producers and workers. Never run v1 and v2 workers concurrently against the same table.

The migration preserves IDs, payloads, scheduling, expiry, errors, and completed/failed history. `retry_count` becomes `attempts`, which counts started attempts, including a successful or interrupted current attempt. Interrupted v1 jobs are requeued immediately, marked failed if exhausted, or expired if their deadline passed. The supplied retry policy applies to **all legacy jobs**; v1 did not persist per-job policy. To preserve an old first retry delay of 120 seconds, explicitly pass `baseDelay: 120`.

v2 records when a job finished (`finished_at`), which `prune()` uses. v1 kept no finish time: legacy completed and failed jobs get their last scheduled time instead, and jobs the migration fails or expires get the migration time.

MySQL creation timestamps are converted to UTC `DATETIME` values. Legacy PostgreSQL creation timestamps without timezone metadata are preserved as stored; audit old historical values separately if sessions previously used non-UTC timezones.

The migration adds `claim_token`, `lease_until`, `finished_at`, and saved retry settings, adds the `expired` status, and removes `locked_at`/`retry_count`. It rebuilds the v1 status indexes with an `id` tiebreaker and adds the `(status, expires_at)` and `lease_until` indexes. SQLite and PostgreSQL migrate transactionally. MySQL/MariaDB DDL commits implicitly: retain the backup. If migration is interrupted, rerun it with the same policy while clients remain stopped. A successful rerun does not reset attempts or statuses.

`Schema::install()` creates new tables, adds missing indexes, and is idempotent for v2 tables. It reports an error for a v1 table; it does not silently migrate it. There is no automatic downgrade: restoring v1 requires restoring the old schema and compatible data from your backup.

## API changes

| v1 | v2 |
| --- | --- |
| `Solo\JobQueue\Schema`, `Worker`, `WorkerLimits` | Moved to `Solo\JobQueue\Storage\Schema`, `Solo\JobQueue\Worker\Worker`, and `Solo\JobQueue\Worker\WorkerLimits` |
| `new RetryPolicy(maxRetries: 3, lockTimeout: 600)` | `new RetryPolicy(maxAttempts: 3)`, plus `JobQueue(leaseDuration: 600)` |
| First delay `2 * baseDelay` | First delay `baseDelay`; then doubles to `maxDelay` |
| Retry policy on each consumer | Policy saved when each job is enqueued |
| `JobQueue(container: $container)` | `JobQueue($storage, codec: new ContainerJobCodec($container))`; the codec and its container are required |
| Jobs with `createFromContainer()` and `JsonSerializable` | Add `implements PersistableJobInterface`; `jsonSerialize()` must return `array` |
| `Worker(batchSize: 10)` | Remove `batchSize`; the worker claims one job at a time. `type` is now the second parameter, so pass arguments by name |
| `JobQueue(autoReclaim: false)` | Removed; use `Worker(maintenanceInterval: null)` and call `maintain()` from cron |
| `reclaimStuck()` | `maintain()`, which also expires overdue pending jobs; returns `requeued`, `failed`, `expired` |
| `markCompleted($id)` / `markFailed($id, $error)` | `complete($claim)` / `fail($claim, $error)`, using the object returned by `claim()` |
| Raw `addJob($payload)` | Use a job with `push()`; implement `JobCodecInterface` for a custom JSON wire format |
| `getPendingJobs()` / `getFailedJobs()` return rows | Both return `list<JobRecord>` with typed fields and UTC dates |
| `processJobs($limit, onlyType: ...)`, `getPendingJobs($limit, onlyType: ...)` | The filter parameter is named `type` everywhere |
| Four statistics statuses | Five statuses, including `expired` |
| `retry($id)` on a failed expired job | Returns false; provide `expiresAt` with a future deadline |
| No history cleanup | `prune($finishedBefore, $statuses)` deletes finished jobs |
| `Solo\Contracts\JobQueue\JobQueueInterface` | Deprecated in `solophp/contracts` 1.9; `JobQueue` implements `JobProducerInterface` for enqueueing and `JobProcessorInterface` for execution from the same namespace |
| `WorkerLimits::reachedReason($count, $unixStartedAt)` | `reachedReason($count, $elapsedSeconds)` |
| `LockGuard` | Removed; use `flock()` directly or a lock library such as `symfony/lock` |

`processJobs($limit, $type)` retains its shape but runs maintenance once and then reserves each job just before execution. It returns processed attempts, including decoding failures and attempts whose lease ended before execution. A zero limit performs no work; a negative limit is rejected. `processNext($type)` executes one job without maintenance.

`claim()` returns a `ClaimedJob` whose `leaseUntil` and `expiresAt` are UTC `DateTimeImmutable` values. `renew($claim)` returns the claim with its extended lease, or null when ownership is lost. `complete()` and `fail()` return false when maintenance has already reclaimed the job, and then do not change the row.

`JobStorageInterface` is internal in v2 and may change in any 2.x release; custom storage implementations are not supported. Construct `DbalJobStorage` and pass it to `JobQueue`.

## Existing jobs and adapters

The standard codec still reads `{job_class, job_data}` and tolerates an absent `job_data` for old stateless jobs. Job classes must implement `PersistableJobInterface`, which declares `jsonSerialize(): array` and `createFromContainer(ContainerInterface $container, array $data): self`. A v1 job with these exact signatures only needs the interface added. Narrow a `jsonSerialize(): mixed` return type to `array`, and give stateless jobs without `jsonSerialize()` one that returns `[]`. A stored job whose class does not implement the interface fails to decode, so deploy the updated classes before starting v2 workers.

Explicit non-array job data is rejected. Legacy jobs already stored with missing data cannot have that data recovered automatically; inspect or recreate them before rollout.

Integrations accepting `Solo\Contracts\JobQueue\JobQueueInterface`, including older async-event-dispatcher queue adapters, must be updated before switching to v2. Use `Solo\Contracts\JobQueue\JobProcessorInterface` when only processing is needed and `JobProducerInterface` when enqueueing is needed. Both ship with `solophp/contracts` 1.9, so adapters do not have to depend on this package. `JobInterface` and `JobQueueListener` are unchanged.

## Operational changes

- Choose `leaseDuration` to cover the longest uninterrupted handler operation. Manual/custom execution can renew the current claim cooperatively. The standard worker has no background heartbeat.
- An attempt can still be completed or failed after its lease ended, until maintenance reclaims the job.
- Consumer operations reject active external transactions and disabled autocommit. Producer inserts may join application transactions.
- MySQL/MariaDB claims run at READ COMMITTED for the claim transaction only. With binary logging enabled, `binlog_format` must be ROW or MIXED (the defaults); `STATEMENT` rejects every claim. Deadlocks, lock wait timeouts, and busy SQLite errors in consumer statements are retried.
- Exceptions from observers, logging, or acknowledgement propagate; they do not invoke the handler failure path. Supervise the process.
- Shutdown and limits apply between individual jobs. Existing signal handlers are restored after the worker stops.
- `Worker` runs maintenance at start and then every 60 seconds by default (`maintenanceInterval`), not before every job.
- `expired` jobs are terminal until explicitly retried with a new deadline. Cron users can call `maintain()`.
- Error text is stored as valid UTF-8 without NUL bytes, truncated to 16 KB.
- Tokens protect queue state, not external effects. Handlers still need idempotency across attempts.
