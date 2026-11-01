<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\JobQueue\RetryPolicy;

final class RetryPolicyTest extends TestCase
{
    public function testBackoffStartsAtBaseAndSaturatesWithoutOverflow(): void
    {
        $policy = new RetryPolicy(baseDelay: 10, maxDelay: 100);
        $this->assertSame(
            [10, 20, 40, 80, 100, 100],
            array_map($policy->backoffSeconds(...), [1, 2, 3, 4, 5, PHP_INT_MAX]),
        );
        $this->assertSame(0, (new RetryPolicy(baseDelay: 0))->backoffSeconds(PHP_INT_MAX));
        $this->assertSame(3600, (new RetryPolicy(baseDelay: PHP_INT_MAX))->backoffSeconds(1));
        $unbounded = new RetryPolicy(baseDelay: PHP_INT_MAX, maxDelay: PHP_INT_MAX);
        $this->assertSame(PHP_INT_MAX, $unbounded->backoffSeconds(99));
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsRejected(array $settings): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryPolicy(...$settings);
    }

    public static function invalidSettings(): array
    {
        return [[[0]], [[3, -1]], [[3, 60, -1]]];
    }

    public function testInvalidAttemptRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RetryPolicy())->backoffSeconds(0);
    }

    public function testPresets(): void
    {
        $this->assertSame(5, RetryPolicy::aggressive()->backoffSeconds(1));
        $this->assertSame(300, RetryPolicy::lenient()->backoffSeconds(1));
    }
}
