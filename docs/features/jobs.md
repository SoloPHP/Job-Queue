# Jobs and Codecs

A job is any `Solo\Contracts\JobQueue\JobInterface`: one method, `handle(): void`. The queue never stores the object itself. A **codec** turns the job into a JSON payload when it is enqueued, and turns the payload back into a job just before a worker runs it.

```php
use Solo\JobQueue\Codec\ContainerJobCodec;
use Solo\JobQueue\JobQueue;

$queue = new JobQueue($storage, codec: new ContainerJobCodec($container));
```

Next to the payload, the queue stores the job's class name in the `name` column, whatever the codec, for logs and [inspection](/features/maintenance#inspecting-jobs).

## ContainerJobCodec

The standard codec stores the class name and the job's data:

```json
{"job_class": "App\\Jobs\\SendEmailJob", "job_data": {"to": "user@example.com", "subject": "Welcome"}}
```

and restores the job by calling `createFromContainer()` on that class with the data and a PSR-11 container. The job asks the container for the services it needs, so the payload holds data only. The codec needs the container on the producer side too, because it is given in its constructor.

`ContainerJobCodec` accepts only jobs that implement [`PersistableJobInterface`](#persistablejobinterface): `encode()` rejects any other job with `InvalidArgumentException`, and `decode()` constructs only a class that exists and implements the interface. The class name comes from the database, so no other class is ever instantiated from it.

A payload without `job_data`, as version 1 stored for jobs without data, restores with an empty array. A `job_data` that is not an object or array is rejected.

## PersistableJobInterface

```php
interface PersistableJobInterface extends JobInterface, JsonSerializable
{
    /** @return array<string, mixed> */
    public function jsonSerialize(): array;

    /** @param array<string, mixed> $data */
    public static function createFromContainer(ContainerInterface $container, array $data): self;
}
```

`jsonSerialize()` returns what to store; `createFromContainer()` receives the same array back, plus the container:

```php
use Psr\Container\ContainerInterface;
use Solo\JobQueue\Codec\PersistableJobInterface;

final class GenerateInvoiceJob implements PersistableJobInterface
{
    public function __construct(
        private readonly int $orderId,
        private readonly ?InvoiceService $invoices = null,
    ) {
    }

    public static function createFromContainer(ContainerInterface $container, array $data): self
    {
        return new self($data['order_id'], $container->get(InvoiceService::class));
    }

    /** @return array{order_id: int} */
    public function jsonSerialize(): array
    {
        return ['order_id' => $this->orderId];
    }

    public function handle(): void
    {
        $this->invoices->generate($this->orderId);
    }
}
```

Store identifiers rather than whole entities: the job runs later, maybe more than once, and should read the current state.

### Renaming a Job Class

The class name is part of every stored payload. After a rename, keep the old class loadable until the jobs queued under its name have run, for example as an alias, or write a codec that stores stable aliases, as below.

## When Decoding Fails

A payload that cannot be decoded — an unknown class, invalid JSON, a container that cannot build a service — counts as a failed attempt. The queue calls `fail()` with the exception, so the job is retried under its [policy](/features/retries) until its attempts run out, and the error is stored with the job. The listener receives `onFailed` without an `onClaimed` before it.

## Custom Codecs

Implement `JobCodecInterface` for another wire format, stable aliases instead of class names, or jobs built without a container:

```php
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Solo\Contracts\JobQueue\JobInterface;
use Solo\JobQueue\Codec\{JobCodecInterface, PersistableJobInterface};

final class AliasCodec implements JobCodecInterface
{
    private const CLASSES = [
        'send-email' => SendEmailJob::class,
        'generate-invoice' => GenerateInvoiceJob::class,
    ];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function encode(JobInterface $job): string
    {
        $alias = array_search($job::class, self::CLASSES, true);
        if ($alias === false || !$job instanceof PersistableJobInterface) {
            throw new InvalidArgumentException('No alias for ' . $job::class);
        }
        return json_encode(['type' => $alias, 'data' => $job->jsonSerialize()], JSON_THROW_ON_ERROR);
    }

    public function decode(string $payload): JobInterface
    {
        $message = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $class = self::CLASSES[$message['type'] ?? ''] ?? throw new InvalidArgumentException('Unknown job type');
        return $class::createFromContainer($this->container, $message['data'] ?? []);
    }
}
```

`encode()` must return valid JSON, because the payload column is JSON or JSONB: the queue checks it with `json_validate()` and throws `InvalidArgumentException` instead of inserting it. PostgreSQL also rejects the `\u0000` escape in JSONB, so keep NUL characters out of job data; encode binary values, for example with base64. Exceptions from `decode()` are [decoding failures](#when-decoding-fails).
