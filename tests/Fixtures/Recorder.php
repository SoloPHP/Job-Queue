<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

final class Recorder
{
    /** @var list<string> */
    public array $items = [];

    /** @var (callable(string): void)|null */
    public $onItem = null;

    public function record(string $value): void
    {
        $this->items[] = $value;
        if ($this->onItem !== null) {
            ($this->onItem)($value);
        }
    }
}
