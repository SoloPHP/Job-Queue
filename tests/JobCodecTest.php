<?php

declare(strict_types=1);

namespace Solo\JobQueue\Tests;

use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Contracts\JobQueue\JobInterface;
use Solo\JobQueue\ContainerJobCodec;
use Solo\JobQueue\PersistableJobInterface;
use Solo\JobQueue\Tests\Fixtures\ArrayContainer;
use Solo\JobQueue\Tests\Fixtures\JobWithoutFactory;
use Solo\JobQueue\Tests\Fixtures\LegacyContainerJob;
use Solo\JobQueue\Tests\Fixtures\Recorder;
use Solo\JobQueue\Tests\Fixtures\SimpleJob;
use Solo\JobQueue\Tests\Fixtures\StatelessJob;
use stdClass;

final class JobCodecTest extends TestCase
{
    public function testJobRoundTripsWithContainerDependencies(): void
    {
        $recorder = new Recorder();
        $codec = new ContainerJobCodec(new ArrayContainer([Recorder::class => $recorder]));

        $decoded = $codec->decode($codec->encode(new SimpleJob('saved value')));
        $decoded->handle();

        $this->assertInstanceOf(SimpleJob::class, $decoded);
        $this->assertSame(['saved value'], $recorder->items);
    }

    public function testPayloadKeepsTheV1WireFormat(): void
    {
        $payload = (new ContainerJobCodec(new ArrayContainer()))->encode(new SimpleJob('saved value'));

        $this->assertSame([
            'job_class' => SimpleJob::class,
            'job_data' => ['value' => 'saved value'],
        ], json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDecodesStatelessPayloadWithoutJobData(): void
    {
        $codec = new ContainerJobCodec(new ArrayContainer());
        $payloads = [
            ['job_class' => StatelessJob::class],
            ['job_class' => StatelessJob::class, 'job_data' => []],
        ];
        foreach ($payloads as $payload) {
            $this->assertInstanceOf(
                StatelessJob::class,
                $codec->decode(json_encode($payload, JSON_THROW_ON_ERROR)),
            );
        }
    }

    #[DataProvider('nonPersistableJobs')]
    public function testEncodingRequiresThePersistableContract(JobInterface $job): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement ' . PersistableJobInterface::class);

        (new ContainerJobCodec(new ArrayContainer()))->encode($job);
    }

    /** @return list<array{JobInterface}> */
    public static function nonPersistableJobs(): array
    {
        return [[new JobWithoutFactory()], [new LegacyContainerJob()]];
    }

    public function testDecodingRejectsAStoredJobWithoutThePersistableContract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not implement ' . PersistableJobInterface::class);

        (new ContainerJobCodec(new ArrayContainer()))->decode(json_encode([
            'job_class' => LegacyContainerJob::class,
            'job_data' => [],
        ], JSON_THROW_ON_ERROR));
    }

    #[DataProvider('invalidJobData')]
    public function testDecodingRejectsExplicitNonArrayData(mixed $data): void
    {
        $codec = new ContainerJobCodec(new ArrayContainer());
        $payload = json_encode([
            'job_class' => SimpleJob::class,
            'job_data' => $data,
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('job_data must be an array');
        $codec->decode($payload);
    }

    /** @return list<array{mixed}> */
    public static function invalidJobData(): array
    {
        return [[null], ['lost data'], [42], [false]];
    }

    public function testDecodingRejectsMalformedJson(): void
    {
        $this->expectException(JsonException::class);

        (new ContainerJobCodec(new ArrayContainer()))->decode('{broken');
    }

    public function testDecodingRejectsMissingJobClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing job_class');

        (new ContainerJobCodec(new ArrayContainer()))->decode('{"job_data":[]}');
    }

    public function testDecodingRejectsNonJobClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not implement');

        (new ContainerJobCodec(new ArrayContainer()))->decode(json_encode([
            'job_class' => stdClass::class,
            'job_data' => [],
        ], JSON_THROW_ON_ERROR));
    }

    public function testDecodingRejectsUnknownClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Job class does not exist: App\\RemovedJob');

        (new ContainerJobCodec(new ArrayContainer()))->decode('{"job_class":"App\\\\RemovedJob","job_data":[]}');
    }
}
