<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Solo\JobQueue\Codec\PersistableJobInterface;

/** Fails with a message no database accepts verbatim: invalid UTF-8, a NUL byte, 70 KB. */
final class UnstorableErrorJob implements PersistableJobInterface
{
    public static function createFromContainer(ContainerInterface $container, array $data): self
    {
        return new self();
    }

    public function handle(): void
    {
        throw new RuntimeException("upstream said \xff\xfe\0" . str_repeat('x', 70000));
    }

    /** @return array{} */
    public function jsonSerialize(): array
    {
        return [];
    }
}
