<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

use Solo\Contracts\JobQueue\JobInterface;

final class JobWithoutFactory implements JobInterface
{
    public function handle(): void
    {
    }
}
