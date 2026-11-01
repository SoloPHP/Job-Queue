<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

use Psr\Container\ContainerInterface;
use Solo\JobQueue\PersistableJobInterface;

final class StatelessJob implements PersistableJobInterface
{
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
