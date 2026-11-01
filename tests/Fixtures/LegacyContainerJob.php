<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

use JsonSerializable;
use Psr\Container\ContainerInterface;
use Solo\Contracts\JobQueue\JobInterface;

/** A v1-style job: the right methods, but without PersistableJobInterface. */
final class LegacyContainerJob implements JobInterface, JsonSerializable
{
    /** @param array<string, mixed> $data */
    public static function createFromContainer(ContainerInterface $container, array $data): self
    {
        return new self();
    }

    public function handle(): void
    {
    }

    /** @return array{} */
    public function jsonSerialize(): array
    {
        return [];
    }
}
