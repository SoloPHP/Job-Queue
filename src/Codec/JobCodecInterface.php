<?php

declare(strict_types=1);

namespace Solo\JobQueue\Codec;

use Solo\Contracts\JobQueue\JobInterface;

/**
 * Converts executable jobs to JSON payloads and back.
 * encode() must return JSON accepted by the database JSON/JSONB column.
 */
interface JobCodecInterface
{
    public function encode(JobInterface $job): string;

    public function decode(string $payload): JobInterface;
}
