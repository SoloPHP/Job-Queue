<?php

declare(strict_types=1);

namespace Solo\JobQueue;

/**
 * Prevents concurrent execution of the same worker using an exclusive file lock.
 * The lock is atomic (flock), works cross-platform, and is auto-released by the
 * OS when the process exits — no PID inspection, no TOCTOU race.
 */
final class LockGuard
{
    /** @var resource|null */
    private $handle;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * Try to acquire the lock. Returns false if another process holds it.
     */
    public function acquire(): bool
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $handle = @fopen($this->file, 'c');
        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->handle = $handle;
        return true;
    }

    /**
     * Release the lock. Safe to call multiple times.
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
