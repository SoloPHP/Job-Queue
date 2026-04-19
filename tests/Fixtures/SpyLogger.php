<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

use Psr\Log\AbstractLogger;

/**
 * In-memory PSR-3 logger that records every call. Used to assert that
 * JobQueue emits the expected level/message/context combinations.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function byMessage(string $message): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(array $r): bool => $r['message'] === $message,
        ));
    }
}
