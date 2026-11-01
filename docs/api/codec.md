# Codec

In `Solo\JobQueue\Codec`. See [Jobs and Codecs](/features/jobs).

## JobCodecInterface

Converts jobs to JSON payloads and back.

```php
interface JobCodecInterface
{
    public function encode(JobInterface $job): string;

    public function decode(string $payload): JobInterface;
}
```

`encode()` must return valid JSON: the queue checks it before inserting and throws `InvalidArgumentException` otherwise. An exception from `decode()` fails the attempt, which is then retried under the job's policy.

---

## ContainerJobCodec

Final and readonly. Stores a [`PersistableJobInterface`](#persistablejobinterface) job as `{"job_class": …, "job_data": …}` and restores it through a PSR-11 container.

```php
public function __construct(ContainerInterface $container)
```

### encode()

```php
public function encode(JobInterface $job): string
```

**Throws:**
- `InvalidArgumentException` — the job does not implement `PersistableJobInterface`
- `JsonException` — its data cannot be encoded

### decode()

```php
public function decode(string $payload): JobInterface
```

Calls `createFromContainer()` on the stored class with the container and the stored data. A payload without `job_data` restores with an empty array.

**Throws:**
- `JsonException` — the payload is not valid JSON
- `InvalidArgumentException` — no `job_class`, a class that does not exist or does not implement `PersistableJobInterface`, or `job_data` that is not an array
- Whatever `createFromContainer()` throws

---

## PersistableJobInterface

The job contract of `ContainerJobCodec`. Extends `Solo\Contracts\JobQueue\JobInterface` and `JsonSerializable`.

```php
interface PersistableJobInterface extends JobInterface, JsonSerializable
{
    /** @return array<string, mixed> */
    public function jsonSerialize(): array;

    /** @param array<string, mixed> $data */
    public static function createFromContainer(ContainerInterface $container, array $data): self;

    public function handle(): void; // from JobInterface
}
```

- `jsonSerialize()` — the data to store; services stay out of it
- `createFromContainer()` — the job rebuilt from that data, with its services taken from the container
- `handle()` — the work
