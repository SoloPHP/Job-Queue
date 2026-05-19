<?php

declare(strict_types=1);

namespace Solo\JobQueue;

/**
 * Job lifecycle state. Single source of truth for the string values used by
 * storage implementations (`status` column for SQL backends, namespaced keys
 * for Redis-style backends, etc.) and as keys in {@see JobQueue::getStats()}.
 */
enum JobStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
}
