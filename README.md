# JobQueue

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/job-queue.svg)](https://packagist.org/packages/solophp/job-queue)
[![License](https://img.shields.io/packagist/l/solophp/job-queue.svg)](https://github.com/solophp/job-queue/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/job-queue.svg)](https://packagist.org/packages/solophp/job-queue)

A modern PHP job queue built on top of Doctrine DBAL with object-oriented job handling.
Supports scheduled execution, retries, job expiration, indexed job types, automatic deletion of completed jobs, and type-safe job classes.

## 📦 Installation

```bash
composer require solophp/job-queue
```

## 📋 Requirements

- **PHP**: >= 8.2
- **Extensions**:
  - `ext-json` - for JSON payload handling
  - `ext-posix` - for LockGuard process locking (optional)
- **Dependencies**:
  - `doctrine/dbal` - for database operations

This package uses Doctrine DBAL for database abstraction, providing support for multiple database platforms (MySQL, PostgreSQL, SQLite, SQL Server, Oracle, etc.) with consistent API.

## ⚙️ Setup

```php
use Solo\JobQueue\JobQueue;
use Doctrine\DBAL\DriverManager;

$connectionParams = [
    'dbname' => 'test',
    'user' => 'username',
    'password' => 'password',
    'host' => 'localhost',
    'driver' => 'pdo_mysql',
];
$connection = DriverManager::getConnection($connectionParams);
$queue = new JobQueue($connection, table: 'jobs', maxRetries: 5, deleteOnSuccess: true);
$queue->install(); // creates the jobs table if not exists
```

## 🚀 Usage

### Create a Job Class

```php
use Solo\Contracts\JobQueue\JobInterface;

class SendEmailJob implements JobInterface
{
    public function __construct(
        private string $to,
        private string $subject,
        private string $body
    ) {}

    public function handle(): void
    {
        // Send email logic here
        mail($this->to, $this->subject, $this->body);
    }
}
```

### Push Jobs to Queue

```php
$job = new SendEmailJob('user@example.com', 'Welcome!', 'Welcome to our service');
$jobId = $queue->push($job);

// With type for filtering
$jobId = $queue->push($job, 'email');

// Schedule for later
$scheduledJob = new SendEmailJob('user@example.com', 'Reminder', 'Don\'t forget!');
$queue->push($scheduledJob, 'email', new DateTimeImmutable('tomorrow'));
```

### Process Jobs

```php
$queue->processJobs(10); // Process up to 10 jobs
```

## 🔒 Using `LockGuard` (optional)

```php
use Solo\JobQueue\LockGuard;

$lockFile = __DIR__ . '/storage/locks/my_worker.lock';
$lock = new LockGuard($lockFile);

if (!$lock->acquire()) {
    exit(0); // Another worker is already running
}

try {
    $queue->processJobs();
} finally {
    $lock->release(); // Optional, auto-released on shutdown
}
```

## 🧰 Features

- **Type-Safe Jobs** – Object-oriented job classes with `JobInterface`
- **Job Retries** – Configurable max retry attempts before marking as failed
- **Job Expiration** – Automatic expiration via `expires_at` timestamp
- **Indexed Job Types** – Fast filtering by `type`
- **Row-Level Locking** – Prevents concurrent execution of the same job
- **Transactional Safety** – All job operations are executed within a transaction
- **Optional Process Locking** – Prevent overlapping workers using `LockGuard`
- **Optional Deletion on Success** – Set `deleteOnSuccess: true` to automatically delete jobs after success
- **Dependency Injection** – Jobs can receive dependencies through constructor

## 🔗 Integration with Async Event-Dispatcher

JobQueue provides seamless integration with [SoloPHP Async Event-Dispatcher](https://github.com/SoloPHP/async-event-dispatcher) through the built-in `SoloJobQueueAdapter` and `AsyncEventJob` classes.

### Basic Setup

```php
use Solo\AsyncEventDispatcher\{AsyncEventDispatcher, ReferenceListenerRegistry, ListenerReference};
use Solo\AsyncEventDispatcher\Adapter\SoloJobQueueAdapter;
use Solo\JobQueue\JobQueue;

// Setup JobQueue
$queue = new JobQueue($connection);
$queue->install();

// Setup AsyncEventDispatcher
$registry = new ReferenceListenerRegistry();
$registry->addReference(UserRegistered::class, new ListenerReference(SendWelcomeEmail::class, 'handle'));

$adapter = new SoloJobQueueAdapter($queue, $container);
$dispatcher = new AsyncEventDispatcher($registry, $adapter);

// Dispatch events - they'll be queued as jobs automatically
$dispatcher->dispatch(new UserRegistered('john@example.com'));

// Process async events
$queue->processJobs(10, 'async_event');
```

The `AsyncEventJob` is automatically used by the adapter to handle event deserialization and listener execution.

For complete async event processing setup, refer to the [Async Event-Dispatcher documentation](https://github.com/SoloPHP/async-event-dispatcher).

## 🧪 API Methods

| Method                                                                                                                | Description                                                    |
|-----------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------|
| `install()`                                                                                                           | Create the jobs table                                          |
| `push(JobInterface $job, ?string $type = null, ?DateTimeImmutable $scheduledAt = null, ?DateTimeImmutable $expiresAt = null)` | Push job to the queue with optional type filtering |
| `addJob(array $payload, ?DateTimeImmutable $scheduledAt = null, ?DateTimeImmutable $expiresAt = null, ?string $type = null)` | Add job to the queue using raw payload data |
| `getPendingJobs(int $limit = 10, ?string $onlyType = null)`                                                          | Retrieve ready-to-run jobs, optionally filtered by type       |
| `markCompleted(int $jobId)`                                                                                           | Mark job as completed                                          |
| `markFailed(int $jobId, string $error = '')`                                                                          | Mark job as failed with error message                         |
| `processJobs(int $limit = 10, ?string $onlyType = null)`                                                             | Process pending jobs                                           |

## 📄 License

This project is open-sourced under the [MIT license](./LICENSE).