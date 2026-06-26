<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExportRunner;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\RecordingWriterFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ClickHouseOutboxExportRunner::class)]
final class ClickHouseOutboxExportRunnerTest
{
    private InMemoryStorage $storage;

    private ClickHouseOutboxExporter $exporter;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-06-11 12:10:00');
            }
        };

        $this->exporter = new ClickHouseOutboxExporter(
            storage: $this->storage,
            router: new MapClickHouseMessageRouter(routes: ['ab.exposure' => ['table' => 't', 'columns' => ['event_id', 'experiment']]]),
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 30),
            clock: $clock,
            writerFactory: new RecordingWriterFactory(),
        );
    }

    public function runOnceExportsASingleBatch(): void
    {
        $this->seed('a');

        $result = $this->runner()->runOnce();

        Assert::same($result->published, 1);
    }

    public function runStopsWhenShouldContinueReturnsFalse(): void
    {
        $lastIteration = 0;

        $this->runner()->run(
            static function (int $iteration) use (&$lastIteration): bool {
                $lastIteration = $iteration;

                return $iteration <= 3;
            },
            static fn(int $seconds): null => null,
        );

        Assert::same($lastIteration, 4);
    }

    public function sleepsBusyThenIdle(): void
    {
        $this->seed('a');
        $sleeps = [];

        $this->runner(idle: 5, busy: 1)->run(
            static fn(int $iteration): bool => $iteration <= 2,
            function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        Assert::same($sleeps, [1, 5]);
    }

    public function rejectsNegativeSleep(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseOutboxExportRunner($this->exporter, idleSleepSeconds: -1);
    }

    public function rejectsNegativeBusySleep(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseOutboxExportRunner($this->exporter, busySleepSeconds: -1);
    }

    public function allowsZeroSleep(): void
    {
        $runner = new ClickHouseOutboxExportRunner($this->exporter, idleSleepSeconds: 0, busySleepSeconds: 0);

        Assert::instanceOf($runner, ClickHouseOutboxExportRunner::class);
    }

    public function usesDefaultSleepIntervalsOfBusyOneIdleFive(): void
    {
        $this->seed('a');
        $sleeps = [];

        (new ClickHouseOutboxExportRunner($this->exporter))->run(
            static fn(int $iteration): bool => $iteration <= 2,
            function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        Assert::same($sleeps, [1, 5]);
    }

    public function returnsZeroedResultWhenLoopNeverRuns(): void
    {
        $this->seed('a');
        $sleeps = [];

        $result = $this->runner()->run(
            static fn(int $iteration): bool => false,
            function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        Assert::same($sleeps, []);
        Assert::same($result->published, 0);
        Assert::same($result->retryScheduled, 0);
        Assert::same($result->terminalFailed, 0);
        Assert::same($result->skipped, 0);
        Assert::same($result->groupCount(), 0);
    }

    private function runner(int $idle = 5, int $busy = 1): ClickHouseOutboxExportRunner
    {
        return new ClickHouseOutboxExportRunner($this->exporter, idleSleepSeconds: $idle, busySleepSeconds: $busy);
    }

    private function seed(string $id): void
    {
        $this->storage->save(new OutboxMessage(
            id: $id,
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            status: OutboxStatus::Pending,
            createdAt: new \DateTimeImmutable('2026-06-11 12:00:00'),
        ));
    }
}
