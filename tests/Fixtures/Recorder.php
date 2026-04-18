<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

final class Recorder
{
    /** @var list<string> */
    public array $items = [];

    public function record(string $value): void
    {
        $this->items[] = $value;
    }
}
