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

    public function testAcquireReturnsTrueAndCreatesFile(): void
    {
        $guard = new LockGuard($this->file);
        $this->assertTrue($guard->acquire());
        $this->assertFileExists($this->file);
        $guard->release();
    }

    public function testSecondAcquireOnSameFileFromOtherGuardFails(): void
    {
        $first = new LockGuard($this->file);
        $this->assertTrue($first->acquire());

        $second = new LockGuard($this->file);
        $this->assertFalse($second->acquire());

        $first->release();
    }

    public function testReleaseAllowsReacquisition(): void
    {
        $first = new LockGuard($this->file);
        $this->assertTrue($first->acquire());
        $first->release();

        $second = new LockGuard($this->file);
        $this->assertTrue($second->acquire());
        $second->release();
    }

    public function testReleaseIsIdempotent(): void
    {
        $guard = new LockGuard($this->file);
        $guard->acquire();
        $guard->release();
        $guard->release();
        $this->addToAssertionCount(1);
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

    public function testCreatesMissingDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/job-queue-test-dir-' . uniqid('', true);
        $file = $dir . '/worker.lock';

        try {
            $guard = new LockGuard($file);
            $this->assertTrue($guard->acquire());
            $this->assertDirectoryExists($dir);
            $guard->release();
        } finally {
            if (file_exists($file)) {
                @unlink($file);
            }
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }
}
