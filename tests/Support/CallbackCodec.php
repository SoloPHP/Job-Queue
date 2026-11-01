<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests\Support;

use Closure;
use Solo\Contracts\JobQueue\JobInterface;
use Solo\JobQueue\Codec\JobCodecInterface;
use Solo\JobQueue\Tests\Fixtures\CallbackJob;

/** Every decoded job runs the same callback; the payload is ignored. */
final readonly class CallbackCodec implements JobCodecInterface
{
    /** @param Closure(): void $handler */
    public function __construct(private Closure $handler)
    {
    }

    public function encode(JobInterface $job): string
    {
        return '{}';
    }

    public function decode(string $payload): JobInterface
    {
        return new CallbackJob($this->handler);
    }
}
