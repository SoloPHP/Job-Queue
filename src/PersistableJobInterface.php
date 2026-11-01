<?php

declare(strict_types=1);

namespace Solo\JobQueue;

use JsonSerializable;
use Psr\Container\ContainerInterface;
use Solo\Contracts\JobQueue\JobInterface;

/**
 * Job contract required by ContainerJobCodec: jsonSerialize() returns the stored data and
 * createFromContainer() rebuilds the job, with its dependencies, from that data.
 */
interface PersistableJobInterface extends JobInterface, JsonSerializable
{
    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array;

    /**
     * @param array<string, mixed> $data
     */
    public static function createFromContainer(ContainerInterface $container, array $data): self;
}
