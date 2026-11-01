<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $date = new DateTimeImmutable('2030-01-01 00:00:00 UTC'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->date;
    }

    public function advance(int $seconds): void
    {
        $this->date = $this->date->modify("+{$seconds} seconds");
    }
}
