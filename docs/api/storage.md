# Storage

In `Solo\JobQueue\Storage`. See [Schema and Databases](/features/schema).

## DbalJobStorage

The Doctrine DBAL persistence that `JobQueue` uses. Final. It writes its own SQL for MySQL, MariaDB, PostgreSQL and SQLite.

```php
public function __construct(Connection $connection, string $table = 'jobs')
```

| Parameter | Type | Description |
|---|---|---|
| `$connection` | `Doctrine\DBAL\Connection` | The connection; consumers need it in autocommit mode |
| `$table` | `string` | The table `Schema::install()` created |

Construction opens no connection: the platform is detected, and the table name quoted, on the first query.

**Throws:**
- `InvalidArgumentException` — a table name that does not match `[a-zA-Z_][a-zA-Z0-9_]*`
- `RuntimeException` — on first use, a platform other than MySQL, MariaDB, PostgreSQL or SQLite

Its interface, `JobStorageInterface`, is internal and may change in any 2.x release: custom storage implementations are not supported. Construct a `DbalJobStorage` and pass it to `JobQueue`.

---

## Schema

Static methods that create and migrate the table.

### install()

```php
public static function install(Connection $connection, string $table = 'jobs'): void
```

Creates the table if it is missing, then creates missing indexes and rebuilds those whose columns differ. Idempotent for a version 2 table.

**Throws:**
- `InvalidArgumentException` — an invalid table name, or on PostgreSQL one too long for distinct index names
- `RuntimeException` — an unsupported platform, or an outdated table that needs `migrate()`

### migrate()

```php
public static function migrate(Connection $connection, RetryPolicy $legacyPolicy, string $table = 'jobs'): void
```

Converts a version 1 table in place, storing `$legacyPolicy` with every existing job; installs a new table when none exists. Run it once, with every client of the table stopped. Rerunning it after a completed migration changes nothing. See [Migrating from Version 1](/features/schema#migrating-from-version-1).

**Throws:**
- `InvalidArgumentException` — an invalid table name, or on PostgreSQL one too long for distinct index names
- `RuntimeException` — an unsupported platform, or a connection inside a transaction or not in autocommit mode
