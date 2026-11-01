<?php

declare(strict_types=1);

// Child process of StressTest: drains the queue, then prints the numbers of the jobs it executed.

use Doctrine\DBAL\DriverManager;
use Solo\Contracts\JobQueue\JobInterface;
use Solo\JobQueue\Codec\JobCodecInterface;
use Solo\JobQueue\JobQueue;
use Solo\JobQueue\Storage\DbalJobStorage;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$params = json_decode((string) getenv('QUEUE_STRESS_PARAMS'), true, 512, JSON_THROW_ON_ERROR);
$connection = DriverManager::getConnection($params);
if ($params['driver'] === 'pdo_sqlite') {
    $connection->executeStatement('PRAGMA busy_timeout = 5000');
}

$executed = new ArrayObject();
$codec = new class ($executed) implements JobCodecInterface {
    /** @param ArrayObject<int, int> $executed */
    public function __construct(private ArrayObject $executed)
    {
    }

    public function encode(JobInterface $job): string
    {
        throw new LogicException('The parent process enqueues the jobs.');
    }

    public function decode(string $payload): JobInterface
    {
        /** @var array{n: int} $data */
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        return new class ($this->executed, $data['n']) implements JobInterface {
            /** @param ArrayObject<int, int> $executed */
            public function __construct(private ArrayObject $executed, private int $number)
            {
            }

            public function handle(): void
            {
                $this->executed->append($this->number);
            }
        };
    }
};

$queue = new JobQueue(new DbalJobStorage($connection), $codec);
while ($queue->processNext()) {
    // Keep claiming until the queue is drained.
}
echo json_encode($executed->getArrayCopy(), JSON_THROW_ON_ERROR);
