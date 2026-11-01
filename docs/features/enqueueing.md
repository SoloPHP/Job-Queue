# Enqueueing

## push()

```php
$id = $queue->push(new SendEmailJob('user@example.com', 'Welcome'), 'email');

$queue->push(
    new SendEmailJob('user@example.com', 'Your trial ends soon'),
    'email',
    scheduledAt: new DateTimeImmutable('+1 hour'),
    expiresAt: new DateTimeImmutable('+1 day'),
);
```

`push()` encodes the job with the [codec](/features/jobs), stores it as `pending` and returns its ID.

| Argument | Default | Effect |
|---|---|---|
| `type` | `'default'` | The job's type, up to 64 characters: see [Types](#types) |
| `scheduledAt` | now | The earliest time the job may run |
| `expiresAt` | `null` | The [deadline](/features/retries#deadlines) after which it no longer runs |

Dates may be in any timezone; the queue stores them in UTC. The queue's [retry policy](/features/retries) is stored with the job at the same time, so a later change to the queue's configuration does not affect jobs already enqueued.

## pushMany()

```php
$jobs = array_map(fn(int $id): ImportRowJob => new ImportRowJob($importId, $id), $rowIds);

$count = $queue->pushMany($jobs, "import-{$importId}");
```

All the jobs share the type, schedule and deadline. They are written in multi-row inserts of up to 1,000 jobs, which keeps each statement under the bound-parameter limits of every database. Each chunk is atomic; if a later chunk fails, the earlier ones stay. The return value is the number of jobs stored. Very large payloads remain subject to the database's packet and statement size limits.

## Types

A type partitions the queue:

- a [worker](/features/worker) or `processJobs($limit, $type)` can run one type only, so slow jobs cannot hold up urgent ones;
- [`getStats($type)`](/features/maintenance#statistics) counts one type, which is how you follow a batch.

A unique type per batch gives you its progress for free:

```php
$type = "import-{$importId}";
$queue->pushMany($jobs, $type);

// Later, from a status endpoint:
$stats = $queue->getStats($type);
$finished = $stats['completed'] + $stats['failed'] + $stats['expired'];
$total = $finished + $stats['pending'] + $stats['in_progress'];
```

## Order

Ready jobs are claimed in order of `scheduled_at`, then ID: first in, first out among jobs due at the same time. A retried job is due again after its backoff, so it queues behind jobs that were already due.

## In a Transaction

`push()` and `pushMany()` may run inside your application's transaction. The job is then stored if and only if your changes commit:

```php
$connection->transactional(function () use ($queue, $order): void {
    $this->orders->save($order);
    $queue->push(new SendOrderConfirmationJob($order->id), 'email');
});
```

This only holds when the queue and your data share the connection. Consumer operations are different: they [require autocommit](/features/processing#transactions), so give workers a connection of their own when the application's connection may be inside a transaction.

## Logging

With a PSR-3 logger, `push()` logs `Job queued` at `info` with the `id`, `name` and `type`, and `pushMany()` logs `Jobs queued` with the `count` and `type`.
