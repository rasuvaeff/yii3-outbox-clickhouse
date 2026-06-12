<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExportRunner;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\RecordingWriterFactory;

#[CoversClass(ClickHouseOutboxExportRunner::class)]
final class ClickHouseOutboxExportRunnerTest extends TestCase
{
    private InMemoryStorage $storage;

    private ClickHouseOutboxExporter $exporter;

    #[\Override]
    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-06-11 12:10:00'));

        $this->exporter = new ClickHouseOutboxExporter(
            storage: $this->storage,
            router: new MapClickHouseMessageRouter(routes: ['ab.exposure' => ['table' => 't', 'columns' => ['event_id', 'experiment']]]),
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 30),
            clock: $clock,
            writerFactory: new RecordingWriterFactory(),
        );
    }

    #[Test]
    public function runOnceExportsASingleBatch(): void
    {
        $this->seed('a');

        $result = $this->runner()->runOnce();

        $this->assertSame(1, $result->published);
    }

    #[Test]
    public function runStopsWhenShouldContinueReturnsFalse(): void
    {
        $lastIteration = 0;

        $this->runner()->run(
            function (int $iteration) use (&$lastIteration): bool {
                $lastIteration = $iteration;

                return $iteration <= 3;
            },
            static fn(int $seconds): null => null,
        );

        $this->assertSame(4, $lastIteration);
    }

    #[Test]
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

        $this->assertSame([1, 5], $sleeps);
    }

    #[Test]
    public function rejectsNegativeSleep(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClickHouseOutboxExportRunner($this->exporter, idleSleepSeconds: -1);
    }

    #[Test]
    public function rejectsNegativeBusySleep(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClickHouseOutboxExportRunner($this->exporter, busySleepSeconds: -1);
    }

    #[Test]
    public function allowsZeroSleep(): void
    {
        // 0 is valid (`< 0`, not `<= 0`): must not throw.
        $runner = new ClickHouseOutboxExportRunner($this->exporter, idleSleepSeconds: 0, busySleepSeconds: 0);

        $this->assertInstanceOf(ClickHouseOutboxExportRunner::class, $runner);
    }

    #[Test]
    public function usesDefaultSleepIntervalsOfBusyOneIdleFive(): void
    {
        $this->seed('a');
        $sleeps = [];

        // Default constructor: busySleepSeconds = 1, idleSleepSeconds = 5.
        (new ClickHouseOutboxExportRunner($this->exporter))->run(
            static fn(int $iteration): bool => $iteration <= 2,
            function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        $this->assertSame([1, 5], $sleeps);
    }

    #[Test]
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

        $this->assertSame([], $sleeps);
        $this->assertSame(0, $result->published);
        $this->assertSame(0, $result->retryScheduled);
        $this->assertSame(0, $result->terminalFailed);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $result->groupCount());
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
