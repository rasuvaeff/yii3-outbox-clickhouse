<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Console;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExportRunner;
use Rasuvaeff\Yii3OutboxClickHouse\Console\ExportClickHouseOutboxCommand;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\RecordingWriterFactory;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ExportClickHouseOutboxCommand::class)]
final class ExportClickHouseOutboxCommandTest
{
    private InMemoryStorage $storage;

    private CommandTester $tester;

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

        $exporter = new ClickHouseOutboxExporter(
            storage: $this->storage,
            router: new MapClickHouseMessageRouter(routes: ['ab.exposure' => ['table' => 't', 'columns' => ['event_id', 'experiment']]]),
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 30),
            clock: $clock,
            writerFactory: new RecordingWriterFactory(),
            fetchLimit: 1,
        );

        $this->tester = new CommandTester(new ExportClickHouseOutboxCommand(new ClickHouseOutboxExportRunner($exporter, idleSleepSeconds: 0, busySleepSeconds: 0)));
    }

    public function onceRunsExactlyOneIteration(): void
    {
        $this->seed(3);

        $exit = $this->tester->execute(['--once' => true, '--max-iterations' => '3']);

        Assert::same($exit, 0);
        Assert::true(str_contains($this->tester->getDisplay(), 'published=1'));
        Assert::count($this->storage->findPending(), 2);
    }

    public function maxIterationsRunsExactlyThatMany(): void
    {
        $this->seed(3);

        $exit = $this->tester->execute(['--max-iterations' => '2']);

        Assert::same($exit, 0);
        Assert::count($this->storage->findPending(), 1);
    }

    public function maxIterationsOfOneRunsExactlyOneIteration(): void
    {
        $this->seed(3);

        $exit = $this->tester->execute(['--max-iterations' => '1']);

        Assert::same($exit, 0);
        Assert::count($this->storage->findPending(), 2);
        Assert::true(str_contains($this->tester->getDisplay(), 'published=1'));
    }

    public function reportsZeroOnEmptyStorage(): void
    {
        $exit = $this->tester->execute(['--once' => true]);

        Assert::same($exit, 0);
        Assert::true(str_contains($this->tester->getDisplay(), 'published=0'));
    }

    private function seed(int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->storage->save(new OutboxMessage(
                id: 'm' . $i,
                type: 'ab.exposure',
                payload: '{"experiment":"x"}',
                status: OutboxStatus::Pending,
                createdAt: new \DateTimeImmutable('2026-06-11 12:0' . $i . ':00'),
            ));
        }
    }
}
