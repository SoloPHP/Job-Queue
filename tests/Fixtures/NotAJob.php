<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

/**
 * Intentionally does NOT implement JobInterface — used to verify instantiate()
 * rejects classes that are not jobs.
 */
final class NotAJob
{
    public function handle(): void
    {
    }
}
