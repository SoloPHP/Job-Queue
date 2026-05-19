<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use PHPUnit\Framework\TestCase;
use Solo\JobQueue\WorkerLimits;

final class WorkerLimitsTest extends TestCase
{
    public function testUnlimitedReachesNoReason(): void
    {
        $limits = WorkerLimits::unlimited();

        $this->assertNull($limits->reachedReason(99999, time() - 99999));
    }

    public function testMaxJobsReason(): void
    {
        $limits = new WorkerLimits(maxJobs: 5);

        $this->assertNull($limits->reachedReason(4, time()));
        $this->assertSame('max_jobs', $limits->reachedReason(5, time()));
    }

    public function testMaxRuntimeReason(): void
    {
        $limits = new WorkerLimits(maxRuntime: 1);

        $this->assertSame('max_runtime', $limits->reachedReason(0, time() - 5));
    }
}
