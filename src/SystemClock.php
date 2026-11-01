<?php

declare(strict_types=1);

namespace Solo\JobQueue;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
