<?php

declare(strict_types=1);

namespace Solo\JobQueue\Codec;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Solo\Contracts\JobQueue\JobInterface;

/**
 * Stores PersistableJobInterface jobs as `{job_class, job_data}` JSON and restores them,
 * with their dependencies, through a PSR-11 container.
 */
final readonly class ContainerJobCodec implements JobCodecInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function encode(JobInterface $job): string
    {
        if (!$job instanceof PersistableJobInterface) {
            throw new InvalidArgumentException(
                sprintf('Job %s must implement %s.', $job::class, PersistableJobInterface::class),
            );
        }

        return json_encode([
            'job_class' => $job::class,
            'job_data' => $job->jsonSerialize(),
        ], JSON_THROW_ON_ERROR);
    }

    public function decode(string $payload): JobInterface
    {
        /** @var mixed $decoded */
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['job_class']) || !is_string($decoded['job_class'])) {
            throw new InvalidArgumentException('Invalid job payload: missing job_class');
        }

        // The class name comes from storage: only persistable job classes may be constructed.
        $jobClass = $decoded['job_class'];
        if (!class_exists($jobClass)) {
            throw new InvalidArgumentException("Job class does not exist: {$jobClass}");
        }
        if (!is_subclass_of($jobClass, PersistableJobInterface::class)) {
            throw new InvalidArgumentException(
                "Class {$jobClass} does not implement " . PersistableJobInterface::class,
            );
        }

        // Older stateless jobs may have no job_data key. Explicit invalid data
        // must never be replaced with an empty array.
        $data = array_key_exists('job_data', $decoded) ? $decoded['job_data'] : [];
        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid job payload: job_data must be an array');
        }

        /** @var array<string, mixed> $data */
        return $jobClass::createFromContainer($this->container, $data);
    }
}
