<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultFailureDecider;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseExportException;
use Rasuvaeff\Yii3OutboxClickHouse\FailureDeciderInterface;
use Rasuvaeff\Yii3OutboxClickHouse\FailureDecision;
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
        $first = $this->storage->getById('a');
        $second = $this->storage->getById('b');
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(OutboxStatus::Published, $first->getStatus());
        $this->assertSame(OutboxStatus::Published, $second->getStatus());
    }

    #[Test]
    public function exportOrFailReturnsResultWhenBatchSucceeds(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));

        $result = $this->exporter(new RecordingWriterFactory())->exportOrFail();

        $this->assertSame(1, $result->published);
        $this->assertFalse($result->hasFailures());
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
    public function exportOrFailThrowsWithResultWhenBatchHasFailures(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $factory = new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')]);

        try {
            $this->exporter($factory)->exportOrFail();
            self::fail('Expected ClickHouseExportException to be thrown');
        } catch (ClickHouseExportException $e) {
            $this->assertSame(1, $e->getResult()->retryScheduled);
            $this->assertSame(0, $e->getResult()->terminalFailed);
            $this->assertSame(
                'ClickHouse export reported failures: 1 retry scheduled, 0 terminal failed',
                $e->getMessage(),
            );
        }
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

    #[Test]
    public function rejectsNonPositiveFetchLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fetch limit must be at least 1, got 0');

        $this->exporter(new RecordingWriterFactory(), fetchLimit: 0);
    }

    #[Test]
    public function allowsFetchLimitOfOne(): void
    {
        // 1 is valid (`< 1`, not `<= 1`).
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));

        $result = $this->exporter(new RecordingWriterFactory(), fetchLimit: 1)->export();

        $this->assertSame(1, $result->published);
    }

    #[Test]
    public function skipsNotReadyMessageThatPrecedesAReadyOne(): void
    {
        // Not-ready first: the skip must `continue`, not `break`, or the ready one is missed.
        $this->storage->save($this->pending(
            id: 'not-ready',
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            attempts: 1,
            lastAttemptAt: new \DateTimeImmutable(self::NOW),
        ));
        $this->storage->save($this->pending(id: 'ready', type: 'ab.exposure', payload: '{"experiment":"y"}'));
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory)->export();

        $this->assertSame(1, $result->skipped);
        $this->assertSame(1, $result->published);
        $this->assertSame([['event_id' => 'ready', 'experiment' => 'y']], $factory->writers['ab_exposures']->rows);
    }

    #[Test]
    public function logsRouteFailureWithContext(): void
    {
        $this->storage->save($this->pending(id: 'bad', type: 'ab.exposure', payload: '{}')); // missing "experiment"
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'ClickHouse outbox route failed',
            $this->callback(static fn(array $context): bool => $context['messageId'] === 'bad' && $context['type'] === 'ab.exposure' && \is_string($context['error'])),
        );

        $this->exporter(new RecordingWriterFactory(), logger: $logger)->export();
    }

    #[Test]
    public function retryableRouteFailureSchedulesRetryAndKeepsMessagePending(): void
    {
        $this->storage->save($this->pending(id: 'first-bad', type: 'ab.exposure', payload: '{}'));
        $this->storage->save($this->pending(id: 'good', type: 'ab.exposure', payload: '{"experiment":"x"}'));

        $result = $this->exporter(new RecordingWriterFactory(), decider: $this->alwaysRetryable())->export();

        $this->assertSame(1, $result->retryScheduled);
        $this->assertSame(1, $result->published);
        $bad = $this->storage->getById('first-bad');
        $this->assertNotNull($bad);
        $this->assertSame(OutboxStatus::Pending, $bad->getStatus());
    }

    #[Test]
    public function successfulGroupResultReportsZeroFailures(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));

        $result = $this->exporter(new RecordingWriterFactory())->export();

        $this->assertCount(1, $result->groups);
        $this->assertSame(1, $result->groups[0]->published);
        $this->assertSame(0, $result->groups[0]->retryScheduled);
        $this->assertSame(0, $result->groups[0]->terminalFailed);
        $this->assertSame(1, $result->groups[0]->messageCount);
    }

    #[Test]
    public function terminalWriteFailureMarksMessageFailed(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $factory = new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')]);

        $result = $this->exporter($factory, decider: $this->alwaysTerminal())->export();

        $this->assertSame(1, $result->terminalFailed);
        $this->assertSame(0, $result->retryScheduled);
        $this->assertSame(1, $result->groups[0]->terminalFailed);
        $message = $this->storage->getById('a');
        $this->assertNotNull($message);
        $this->assertSame(OutboxStatus::Failed, $message->getStatus());
    }

    #[Test]
    public function logsGroupFailureWithContext(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $factory = new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'ClickHouse outbox export group failed',
            $this->callback(static fn(array $context): bool => $context['table'] === 'ab_exposures' && $context['messageCount'] === 1 && \is_string($context['error'])),
        );

        $this->exporter($factory, logger: $logger)->export();
    }

    #[Test]
    public function accumulatesRetryAndTerminalAcrossGroups(): void
    {
        // Two groups (two tables): exposures terminal-fail first, conversions retry second.
        $this->storage->save($this->pending(id: 'exp', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $this->storage->save($this->pending(id: 'conv', type: 'ab.conversion', payload: '{"experiment":"x","goal":"buy"}'));
        $factory = new RecordingWriterFactory(failTables: [
            'ab_exposures' => new ClickHouseWriteException('down'),
            'ab_conversions' => new ClickHouseWriteException('down'),
        ]);
        $decider = new class implements FailureDeciderInterface {
            #[\Override]
            public function decide(OutboxMessage $message, \Throwable $e): FailureDecision
            {
                return $message->getType() === 'ab.exposure' ? FailureDecision::Terminal : FailureDecision::Retryable;
            }
        };

        $result = $this->exporter($factory, decider: $decider)->export();

        $this->assertSame(1, $result->retryScheduled);
        $this->assertSame(1, $result->terminalFailed);
        $this->assertSame(0, $result->published);
        $this->assertSame(1, $result->groups[0]->terminalFailed);
        $this->assertSame(0, $result->groups[1]->terminalFailed);
    }

    private function alwaysRetryable(): FailureDeciderInterface
    {
        return new class implements FailureDeciderInterface {
            #[\Override]
            public function decide(OutboxMessage $message, \Throwable $e): FailureDecision
            {
                return FailureDecision::Retryable;
            }
        };
    }

    private function alwaysTerminal(): FailureDeciderInterface
    {
        return new class implements FailureDeciderInterface {
            #[\Override]
            public function decide(OutboxMessage $message, \Throwable $e): FailureDecision
            {
                return FailureDecision::Terminal;
            }
        };
    }

    private function exporter(
        RecordingWriterFactory $factory,
        ?FailureDeciderInterface $decider = null,
        ?LoggerInterface $logger = null,
        int $fetchLimit = 1000,
    ): ClickHouseOutboxExporter {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable(self::NOW));

        return new ClickHouseOutboxExporter(
            storage: $this->storage,
            router: new MapClickHouseMessageRouter(routes: self::ROUTES),
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 30),
            clock: $clock,
            writerFactory: $factory,
            failureDecider: $decider ?? new DefaultFailureDecider(),
            fetchLimit: $fetchLimit,
            logger: $logger ?? new NullLogger(),
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
