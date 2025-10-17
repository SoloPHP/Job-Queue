<?php

declare(strict_types=1);

namespace Solo\JobQueue;

/**
 * LockGuard prevents parallel execution of a process by using a lock file.
 * Useful in cron-based task workers or long-running scripts to avoid overlaps.
 */
final class LockGuard
{
    /**
     * Full path to the lock file.
     */
    private string $file;

    /**
     * Indicates whether the current instance owns the lock.
     */
    private bool $active = false;

    /**
     * @param string $file Absolute path to the lock file
     */
    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /**
     * Attempts to acquire the lock.
     * If the lock file exists and the process is still running — returns false.
     * If the lock file is stale or missing, creates a new lock.
     *
     * @return bool True if lock acquired, false otherwise
     */
    public function acquire(): bool
    {
        if (!is_dir(dirname($this->file))) {
            mkdir(dirname($this->file), 0775, true);
        }

        if (file_exists($this->file)) {
            $pid = file_get_contents($this->file);
            if ($pid && posix_kill((int)$pid, 0)) {
                return false;
            }
            unlink($this->file);
        }

        file_put_contents($this->file, getmypid());
        $this->active = true;

        register_shutdown_function(fn() => $this->release());

        return true;
    }

    /**
     * Releases the lock by removing the lock file.
     */
    public function release(): void
    {
        if ($this->active && file_exists($this->file)) {
            unlink($this->file);
        }
        $this->active = false;
    }
}
