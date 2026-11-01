<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Solo\JobQueue\PersistableJobInterface;

final class FailingJob implements PersistableJobInterface
{
    public function __construct(private readonly string $message)
    {
    }

    public static function createFromContainer(ContainerInterface $container, array $data): self
    {
        /** @var string $message */
        $message = $data['message'] ?? 'boom';
        return new self($message);
    }

    public function handle(): void
    {
        throw new RuntimeException($this->message);
    }

    /** @return array{message: string} */
    public function jsonSerialize(): array
    {
        return ['message' => $this->message];
    }
}
