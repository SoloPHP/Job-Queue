<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Fixtures;

use Solo\Contracts\JobQueue\JobQueueListener;
use Throwable;

final class RecordingListener implements JobQueueListener
{
    /** @var list<array{event: string, ...}> */
    public array $events = [];

    public function onClaimed(int $jobId, string $jobClass): void
    {
        $this->events[] = ['event' => 'claimed', 'id' => $jobId, 'class' => $jobClass];
    }

    public function onCompleted(int $jobId): void
    {
        $this->events[] = ['event' => 'completed', 'id' => $jobId];
    }

    public function onFailed(int $jobId, Throwable|string $error, bool $permanent): void
    {
        $this->events[] = [
            'event'     => 'failed',
            'id'        => $jobId,
            'permanent' => $permanent,
            'error'     => $error instanceof Throwable ? $error::class : $error,
        ];
    }

    public function onReclaimed(int $requeuedCount, int $permanentlyFailedCount): void
    {
        $this->events[] = [
            'event'    => 'reclaimed',
            'requeued' => $requeuedCount,
            'failed'   => $permanentlyFailedCount,
        ];
    }
}
