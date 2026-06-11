<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\RecordingWriterFactory;

#[CoversClass(ClickHouseOutboxExporter::class)]
final class ClickHouseOutboxExporterTest extends TestCase
{
    private const array ROUTES = [
        'ab.exposure' => ['table' => 'ab_exposures', 'columns' => ['event_id', 'experiment']],
        'ab.conversion' => ['table' => 'ab_conversions', 'columns' => ['event_id', 'experiment', 'goal']],
    ];

    private const string NOW = '2026-06-11 12:10:00';

    private InMemoryStorage $storage;

    #[\Override]
    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
    }

    #[Test]
    public function returnsEmptyResultWhenNothingPending(): void
    {
        $result = $this->exporter(new RecordingWriterFactory())->export();

        $this->assertSame(0, $result->totalHandled());
        $this->assertSame(0, $result->groupCount());
        $this->assertSame(0, $result->skipped);
    }

    #[Test]
    public function batchesOneTypeIntoOneGroupAndMarksPublished(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $this->storage->save($this->pending(id: 'b', type: 'ab.exposure', payload: '{"experiment":"y"}'));
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory)->export();

        $this->assertSame(2, $result->published);
        $this->assertSame(1, $result->groupCount());
        $this->assertCount(1, $factory->created);
        $this->assertSame('ab_exposures', $factory->created[0]['table']);
        $this->assertSame([
            ['event_id' => 'a', 'experiment' => 'x'],
            ['event_id' => 'b', 'experiment' => 'y'],
        ], $factory->writers['ab_exposures']->rows);
        $this->assertSame([], $this->storage->findPending());
    }

    #[Test]
    public function splitsDifferentTypesIntoSeparateGroups(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $this->storage->save($this->pending(id: 'b', type: 'ab.conversion', payload: '{"experiment":"x","goal":"buy"}'));
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory)->export();

        $this->assertSame(2, $result->published);
        $this->assertSame(2, $result->groupCount());
        $this->assertCount(2, $factory->created);
        $this->assertCount(1, $factory->writers['ab_exposures']->rows);
        $this->assertCount(1, $factory->writers['ab_conversions']->rows);
    }

    #[Test]
    public function skipsMessagesNotReadyForRetry(): void
    {
        $this->storage->save($this->pending(
            id: 'a',
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            attempts: 1,
            lastAttemptAt: new \DateTimeImmutable(self::NOW),
        ));
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory)->export();

        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->totalHandled());
        $this->assertSame([], $factory->created);
        $message = $this->storage->getById('a');
        $this->assertNotNull($message);
        $this->assertSame(OutboxStatus::Pending, $message->getStatus());
    }

    #[Test]
    public function terminalRouteFailureMarksMessageFailed(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{}')); // missing "experiment"
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory)->export();

        $this->assertSame(1, $result->terminalFailed);
        $this->assertSame(0, $result->published);
        $this->assertSame([], $factory->created);
        $message = $this->storage->getById('a');
        $this->assertNotNull($message);
        $this->assertSame(OutboxStatus::Failed, $message->getStatus());
    }

    #[Test]
    public function retryableWriteFailureKeepsMessagePendingWithIncrementedAttempts(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $factory = new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')]);

        $result = $this->exporter($factory)->export();

        $this->assertSame(1, $result->retryScheduled);
        $this->assertSame(0, $result->published);
        $this->assertTrue($result->hasFailures());
        $message = $this->storage->getById('a');
        $this->assertNotNull($message);
        $this->assertSame(OutboxStatus::Pending, $message->getStatus());
        $this->assertSame(1, $message->getAttempts());
    }

    #[Test]
    public function fetchLimitScopesThePoll(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->storage->save($this->pending(id: 'm' . $i, type: 'ab.exposure', payload: '{"experiment":"x"}'));
        }
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory)->export(limit: 2);

        $this->assertSame(2, $result->published);
        $this->assertCount(3, $this->storage->findPending());
    }

    private function exporter(RecordingWriterFactory $factory): ClickHouseOutboxExporter
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable(self::NOW));

        return new ClickHouseOutboxExporter(
            storage: $this->storage,
            router: new MapClickHouseMessageRouter(routes: self::ROUTES),
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 30),
            clock: $clock,
            writerFactory: $factory,
        );
    }

    private function pending(
        string $id,
        string $type,
        string $payload,
        int $attempts = 0,
        ?\DateTimeImmutable $lastAttemptAt = null,
    ): OutboxMessage {
        return new OutboxMessage(
            id: $id,
            type: $type,
            payload: $payload,
            status: OutboxStatus::Pending,
            createdAt: new \DateTimeImmutable('2026-06-11 12:00:00'),
            attempts: $attempts,
            lastAttemptAt: $lastAttemptAt,
        );
    }
}
