<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use PHPUnit\Framework\TestCase;
use Solo\JobQueue\RetryPolicy;

final class RetryPolicyTest extends TestCase
{
    public function testBackoffSecondsIsExponentialWithCap(): void
    {
        $policy = new RetryPolicy(baseDelay: 10, maxDelay: 100);

        $this->assertSame(20, $policy->backoffSeconds(1));   // 10 * 2
        $this->assertSame(40, $policy->backoffSeconds(2));   // 10 * 4
        $this->assertSame(80, $policy->backoffSeconds(3));   // 10 * 8
        $this->assertSame(100, $policy->backoffSeconds(4));  // capped
        $this->assertSame(100, $policy->backoffSeconds(99)); // still capped
    }

    public function testAggressivePresetHasShortBackoff(): void
    {
        $policy = RetryPolicy::aggressive();

        $this->assertGreaterThan(3, $policy->maxRetries);
        $this->assertLessThan(30, $policy->baseDelay);
    }

    public function testLenientPresetHasLongBackoff(): void
    {
        $policy = RetryPolicy::lenient();

        $this->assertGreaterThan(5, $policy->maxRetries);
        $this->assertGreaterThan(60, $policy->baseDelay);
    }
}
