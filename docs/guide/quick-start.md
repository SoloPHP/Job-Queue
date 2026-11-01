# Quick Start

## Define a Job

A job holds its data and does its work in `handle()`. With [`ContainerJobCodec`](/features/jobs#containerjobcodec) it implements `PersistableJobInterface`: `jsonSerialize()` returns the data to store, and `createFromContainer()` rebuilds the job from that data, taking its services from the container.

```php
use Psr\Container\ContainerInterface;
use Solo\JobQueue\Codec\PersistableJobInterface;

final class SendEmailJob implements PersistableJobInterface
{
    public function __construct(
        private readonly string $to,
        private readonly string $subject,
        private readonly ?Mailer $mailer = null,
    ) {
    }

    public static function createFromContainer(ContainerInterface $container, array $data): self
    {
        return new self($data['to'], $data['subject'], $container->get(Mailer::class));
    }

    /** @return array{to: string, subject: string} */
    public function jsonSerialize(): array
    {
        return ['to' => $this->to, 'subject' => $this->subject];
    }

    public function handle(): void
    {
        $this->mailer->send($this->to, $this->subject);
    }
}
```

Only data is stored, never services: the mailer comes from the container when a worker restores the job.

## Enqueue

```php
$id = $queue->push(new SendEmailJob('user@example.com', 'Welcome'), 'email');

// Not before an hour from now, and not at all after tomorrow.
$queue->push(
    new SendEmailJob('user@example.com', 'Your trial ends soon'),
    'email',
    scheduledAt: new DateTimeImmutable('+1 hour'),
    expiresAt: new DateTimeImmutable('+1 day'),
);

$count = $queue->pushMany($jobs, 'import');
```

The second argument is the job's **type**: workers can be limited to one type, and statistics can be counted per type. Without one a job gets the type `default`. See [Enqueueing](/features/enqueueing).

## Run a Worker

```php
// bin/worker.php
use Solo\JobQueue\Worker\{Worker, WorkerLimits};

$worker = new Worker(
    queue: $queue,
    type: 'email',
    limits: new WorkerLimits(maxJobs: 1000, maxRuntime: 3600, maxMemoryMb: 256),
    logger: $logger,
);
$worker->run();
```

The worker claims one job, runs it, acknowledges it, and repeats; it sleeps for a second when nothing is ready. SIGTERM and SIGINT let the current job finish before it exits, and so does reaching a limit, so run it under systemd, Supervisor or another process supervisor that starts it again. See [Worker](/features/worker).

## Or Process from Cron

Without a long-running process, a cron job can drain the queue every minute:

```php
$queue->processJobs(100);          // maintenance, then up to 100 jobs of any type
$queue->processJobs(100, 'email'); // only email jobs
```

## Inspect and Retry

```php
$stats = $queue->getStats('email');
// ['pending' => 7, 'in_progress' => 1, 'completed' => 192, 'failed' => 2, 'expired' => 0]

foreach ($queue->getFailedJobs(limit: 20, type: 'email') as $record) {
    printf("#%d %s after %d attempts: %s\n", $record->id, $record->name, $record->attempts, $record->error);
}

$queue->retry($id); // a failed job runs again, with a fresh attempt budget
```

A job that fails is retried with backoff until its attempts run out; then it is `failed`. See [Retries and Deadlines](/features/retries).

## Clean Up

Completed and failed jobs stay in the table until you prune them:

```php
use Solo\JobQueue\JobStatus;

$queue->prune(new DateTimeImmutable('-7 days')); // completed jobs finished over a week ago
$queue->prune(new DateTimeImmutable('-30 days'), [JobStatus::Failed, JobStatus::Expired]);
```

## Next Steps

- [Processing and Leases](/features/processing) — Claims, tokens and what happens when a worker dies
- [Delivery and Errors](/features/delivery) — At-least-once delivery and idempotent handlers
- [API Reference](/api/job-queue) — Every method of `JobQueue`
