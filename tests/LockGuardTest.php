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
        // Touch a regular file, then try to use it as a parent directory. mkdir
        // fails because the path exists but is not a directory — independent of
        // filesystem permissions, so this works under root too.
        $blocker = sys_get_temp_dir() . '/job-queue-blocker-' . uniqid('', true);
        touch($blocker);

        try {
            $guard = new LockGuard($blocker . '/worker.lock');
            $this->assertFalse($guard->acquire());
        } finally {
            @unlink($blocker);
        }
    }

    public function testAcquireFailsWhenFileCannotBeOpened(): void
    {
        // Open() on a path that exists as a directory fails: dirname() resolves
        // to a real directory so the mkdir branch is skipped, then fopen() with
        // mode 'c' on a directory returns false.
        $dir = sys_get_temp_dir() . '/job-queue-isdir-' . uniqid('', true);
        mkdir($dir);

        try {
            $guard = new LockGuard($dir);
            $this->assertFalse($guard->acquire());
        } finally {
            @rmdir($dir);
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
