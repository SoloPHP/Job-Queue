<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Worker;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\JobQueue\Worker\WorkerLimits;

final class WorkerLimitsTest extends TestCase
{
    public function testUnlimitedReachesNoReason(): void
    {
        $limits = WorkerLimits::unlimited();

        $this->assertNull($limits->reachedReason(99999, 99999));
    }

    public function testMaxJobsReason(): void
    {
        $limits = new WorkerLimits(maxJobs: 5);

        $this->assertNull($limits->reachedReason(4, 0));
        $this->assertSame('max_jobs', $limits->reachedReason(5, 0));
    }

    public function testMaxRuntimeReason(): void
    {
        $limits = new WorkerLimits(maxRuntime: 1);

        $this->assertNull($limits->reachedReason(0, 0.999));
        $this->assertSame('max_runtime', $limits->reachedReason(0, 1.0));
    }

    #[DataProvider('negativeLimits')]
    public function testNegativeLimitsAreRejected(int $maxJobs, int $maxRuntime, int $maxMemoryMb): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WorkerLimits($maxJobs, $maxRuntime, $maxMemoryMb);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function negativeLimits(): iterable
    {
        yield 'jobs' => [-1, 0, 0];
        yield 'runtime' => [0, -1, 0];
        yield 'memory' => [0, 0, -1];
    }
}
