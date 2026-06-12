<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Console;

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
use Rasuvaeff\Yii3OutboxClickHouse\Console\ExportClickHouseOutboxCommand;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\RecordingWriterFactory;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ExportClickHouseOutboxCommand::class)]
final class ExportClickHouseOutboxCommandTest extends TestCase
{
    private InMemoryStorage $storage;

    private CommandTester $tester;

    #[\Override]
    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-06-11 12:10:00'));

        $exporter = new ClickHouseOutboxExporter(
            storage: $this->storage,
            router: new MapClickHouseMessageRouter(routes: ['ab.exposure' => ['table' => 't', 'columns' => ['event_id', 'experiment']]]),
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 30),
            clock: $clock,
            writerFactory: new RecordingWriterFactory(),
        );

        $this->tester = new CommandTester(new ExportClickHouseOutboxCommand(new ClickHouseOutboxExportRunner($exporter, idleSleepSeconds: 0, busySleepSeconds: 0)));
    }

    #[Test]
    public function onceRunsASingleBatchAndReports(): void
    {
        $this->storage->save(new OutboxMessage(
            id: 'a',
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            status: OutboxStatus::Pending,
            createdAt: new \DateTimeImmutable('2026-06-11 12:00:00'),
        ));

        $exit = $this->tester->execute(['--once' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('published=1', $this->tester->getDisplay());
    }

    #[Test]
    public function maxIterationsBoundsTheLoop(): void
    {
        $exit = $this->tester->execute(['--max-iterations' => '2']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('published=0', $this->tester->getDisplay());
    }
}
