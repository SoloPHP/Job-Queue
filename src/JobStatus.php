<?php

declare(strict_types=1);

namespace Solo\JobQueue;

/** Job lifecycle state: the stored `status` value and a key of {@see JobQueue::getStats()}. */
enum JobStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
