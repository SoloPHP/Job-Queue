<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

use JsonSerializable;
use Psr\Container\ContainerInterface;
use Solo\Contracts\JobQueue\JobInterface;

final class SimpleJob implements JobInterface, JsonSerializable
{
    private ?Recorder $recorder = null;

    public function __construct(private readonly string $value)
    {
    }

    public static function createFromContainer(ContainerInterface $container, array $data): self
    {
        /** @var string $value */
        $value = $data['value'] ?? '';
        $job = new self($value);
        /** @var Recorder $recorder */
        $recorder = $container->get(Recorder::class);
        $job->recorder = $recorder;
        return $job;
    }

    public function handle(): void
    {
        $this->recorder?->record($this->value);
    }

    /** @return array{value: string} */
    public function jsonSerialize(): array
    {
        return ['value' => $this->value];
    }
}
