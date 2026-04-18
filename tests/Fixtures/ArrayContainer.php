<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ArrayContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $services
     */
    public function __construct(private array $services = [])
    {
    }

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->services)) {
            throw new class ("Service not found: {$id}") extends RuntimeException implements NotFoundExceptionInterface {
            };
        }
        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
