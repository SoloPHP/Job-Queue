# Changelog

## 2.0.0 — Unreleased

A rewrite with a new schema and execution API. Read [UPGRADING.md](UPGRADING.md) before upgrading.

### Added

- Claims with ownership tokens and expiring leases: `claim()`, `complete()`, `fail()` and `renew()` work on a `ClaimedJob`.
- Retry policy persisted with each job; the first retry waits `baseDelay`.
- `expired` status for jobs past their `expiresAt` deadline; `retry($id, $expiresAt)` revives them.
- `maintain()` recovers expired leases and expires overdue jobs; `processNext()` runs one job without maintenance.
- `Worker` option `maintenanceInterval` (default 60 seconds).
- `prune()` and the `finished_at` column for deleting finished history.
- `JobRecord` read model for `getPendingJobs()` and `getFailedJobs()`.
- `JobQueue` implements `JobProducerInterface` and `JobProcessorInterface` from `solophp/contracts` 1.9.
- `Codec` namespace: `JobCodecInterface`, `ContainerJobCodec` and `PersistableJobInterface`.
- PSR-20 clock injection.
- `Schema::migrate()` for v1 tables.

### Changed

- Classes are grouped by component: `Schema` moved to `Solo\JobQueue\Storage`, `Worker` and `WorkerLimits` to `Solo\JobQueue\Worker`.
- MySQL/MariaDB claims run at READ COMMITTED, so concurrent workers no longer deadlock. Servers with binary logging need `binlog_format` ROW or MIXED.
- Consumer statements aborted by deadlocks, lock wait timeouts or busy SQLite databases are retried.
- Expiry maintenance uses a `(status, expires_at)` index instead of scanning every pending job.
- Attempts can be acknowledged after their lease ended, until maintenance reclaims them.
- Stored error text is valid UTF-8 without NUL bytes, at most 16 KB.
- `DbalJobStorage` connects on first use rather than when constructed.
- `JobStorageInterface` is internal.
- `ext-mbstring` and `solophp/contracts` ^1.9 are required; `ext-json` is no longer listed.

### Removed

- `addJob()`, `markCompleted()`, `markFailed()`, `reclaimStuck()` and the `autoReclaim` option.
- Jobs that do not implement `PersistableJobInterface`.
- The `Solo\Contracts\JobQueue\JobQueueInterface` implementation.
- `LockGuard`.
