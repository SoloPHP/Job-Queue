<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

use Closure;
use Solo\Contracts\JobQueue\JobInterface;

final readonly class CallbackJob implements JobInterface
{
    /** @param Closure(): void $handler */
    public function __construct(private Closure $handler)
    {
    }

    public function handle(): void
    {
        ($this->handler)();
    }
}
