# Schema and Databases

## Installing

```php
use Solo\JobQueue\Storage\Schema;

Schema::install($connection);          // the `jobs` table
Schema::install($connection, 'queue'); // a table of your own name
```

`install()` creates the table when it is missing, then creates missing indexes and rebuilds any whose columns differ from the definition. It is idempotent for a version 2 table, so it can run on every deploy. It refuses an outdated table — one from version 1, or one whose migration was interrupted — with a `RuntimeException` asking for [`Schema::migrate()`](#migrating-from-version-1); it never migrates silently. An unsupported platform throws `RuntimeException`.

Give [`DbalJobStorage`](/api/storage#dbaljobstorage) the same table name as `install()`.

## Columns

| Column | Holds |
|---|---|
| `id` | The job's ID |
| `name` | The job's class name |
| `type` | The [type](/features/enqueueing#types), `default` unless given |
| `payload` | The [codec](/features/jobs)'s JSON: `JSON` on MySQL and MariaDB, `JSONB` on PostgreSQL, `TEXT` on SQLite |
| `status` | `pending`, `in_progress`, `completed`, `failed` or `expired` |
| `attempts` | Attempts started, the current one included |
| `claim_token` | The current attempt's token; `NULL` when no attempt runs |
| `lease_until` | When the current attempt's lease ends |
| `max_attempts`, `base_delay`, `max_delay` | The [retry policy](/features/retries) stored with the job |
| `error` | The last attempt's [error text](/features/delivery#error-text) |
| `scheduled_at` | The earliest time the job may run next |
| `expires_at` | The [deadline](/features/retries#deadlines), or `NULL` |
| `finished_at` | When the job completed, failed or expired; used by [`prune()`](/features/maintenance#pruning-history) |
| `created_at` | When the job was enqueued |

Every time is a UTC `DATETIME`, or `TIMESTAMP` without time zone on PostgreSQL. The queue writes its own SQL against these columns; extra columns of your own are left alone.

## Indexes

| Columns | Serves |
|---|---|
| `(status, scheduled_at, id)` | Claims and the list of ready jobs |
| `(type, status, scheduled_at, id)` | Claims and statistics for one type |
| `(status, expires_at)` | Expiring overdue jobs |
| `(lease_until)` | Finding abandoned attempts |

## Table Names

A table name matches `[a-zA-Z_][a-zA-Z0-9_]*`; anything else throws `InvalidArgumentException`. The name is quoted in SQL.

- On **PostgreSQL** the name is folded to lower case, as PostgreSQL does for unquoted names, so `Jobs` and `jobs` are the same table, as they were in version 1. Index names are `idx_<table>_<columns>`, cut to PostgreSQL's 63 bytes, and a name so long that two indexes would end up with the same name — 51 characters or more — is rejected.
- On **MySQL and MariaDB** index names are per table: `idx_status_scheduled` and so on.
- On **SQLite** index names are `idx_<table>_<columns>`.

## MySQL and MariaDB

- MySQL 8.0 or later, MariaDB 10.6 or later: both have `SKIP LOCKED`.
- Claims run at READ COMMITTED for the claim's own short transaction, whatever the session's isolation level, because under REPEATABLE READ concurrent claims deadlock on gap locks of the status index.
- With binary logging enabled, `binlog_format` must be `ROW` or `MIXED`, the defaults of both servers. Under `STATEMENT`, InnoDB refuses writes at READ COMMITTED and every claim fails.
- The table uses InnoDB and `utf8mb4`.

## PostgreSQL

- Claims select with `FOR UPDATE SKIP LOCKED` inside one `UPDATE … RETURNING` statement.
- JSONB rejects the `\u0000` escape: keep NUL characters out of job data.
- Times are `TIMESTAMP` columns holding UTC values.

## SQLite

- SQLite 3.35 or later, for `UPDATE … RETURNING`.
- One writer at a time: claims wait for the write lock. Set `PRAGMA busy_timeout` on each connection, and use WAL mode when several processes share the database file. Busy errors are retried a few times; beyond that they propagate.

## Migrating from Version 1

A version 1 table needs one explicit migration. Stop every producer, worker and cron job that uses the table, back it up, deploy version 2, and run:

```php
use Solo\JobQueue\RetryPolicy;
use Solo\JobQueue\Storage\Schema;

Schema::migrate(
    $connection,
    new RetryPolicy(maxAttempts: 3, baseDelay: 120, maxDelay: 3600),
    table: 'jobs',
);
```

The migration:

- adds the version 2 columns and the `expired` status, and removes `retry_count` and `locked_at` once their data is converted;
- stores the policy you pass with **every existing job**, because version 1 kept no policy per job. Version 1 waited `2 × baseDelay` before the first retry, so pass twice your old `baseDelay` to keep the same delays;
- turns `retry_count` into `attempts`, counting started attempts: a completed or interrupted attempt counts;
- requeues jobs that were `in_progress` at once, as their attempt was interrupted; a pending or interrupted job becomes `expired` when its deadline has passed, or `failed` when its attempts are used up;
- gives finished jobs a `finished_at`: their last scheduled time, since version 1 kept no finish time, or the migration time for jobs it fails or expires;
- converts MySQL's `created_at` from `TIMESTAMP` to a UTC `DATETIME`;
- rebuilds the version 1 indexes with the `id` tiebreaker and adds the new ones.

On SQLite and PostgreSQL the migration is one transaction. On MySQL and MariaDB each DDL statement commits on its own, so keep the backup: if the migration is interrupted, run it again with the same policy while everything stays stopped. A second run of a completed migration changes nothing, and `install()` refuses a half-migrated table. `migrate()` on a missing table installs a new one.

[UPGRADING.md](https://github.com/SoloPHP/Job-Queue/blob/main/UPGRADING.md) lists the API changes that come with version 2.
