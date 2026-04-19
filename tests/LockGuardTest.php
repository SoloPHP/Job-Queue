<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use PHPUnit\Framework\TestCase;
use Solo\JobQueue\LockGuard;

final class LockGuardTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/job-queue-test-' . uniqid('', true) . '.lock';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->file)) {
            @unlink($this->file);
        }
    }

    public function testSecondAcquireOnSameFileFromOtherGuardFails(): void
    {
        $first = new LockGuard($this->file);
        $this->assertTrue($first->acquire());

        $second = new LockGuard($this->file);
        $this->assertFalse($second->acquire());

        $first->release();
    }

    public function testAcquireFailsWhenDirectoryCannotBeCreated(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Cannot reliably test unwritable paths as root.');
        }

        $readonly = sys_get_temp_dir() . '/job-queue-ro-' . uniqid('', true);
        mkdir($readonly, 0500, true);

        try {
            $guard = new LockGuard($readonly . '/nested/worker.lock');
            $this->assertFalse($guard->acquire());
        } finally {
            @chmod($readonly, 0755);
            @rmdir($readonly);
        }
    }

    public function testDestructorReleasesLock(): void
    {
        $first = new LockGuard($this->file);
        $this->assertTrue($first->acquire());
        unset($first);

        $second = new LockGuard($this->file);
        $this->assertTrue($second->acquire());
        $second->release();
    }
}
