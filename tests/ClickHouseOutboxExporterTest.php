<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseWriterFactoryInterface;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultFailureDecider;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseExportException;
use Rasuvaeff\Yii3OutboxClickHouse\FailureDeciderInterface;
use Rasuvaeff\Yii3OutboxClickHouse\FailureDecision;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\RecordingWriterFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ClickHouseOutboxExporter::class)]
final class ClickHouseOutboxExporterTest
{
    private const array ROUTES = [
        'ab.exposure' => ['table' => 'ab_exposures', 'columns' => ['event_id', 'experiment']],
        'ab.conversion' => ['table' => 'ab_conversions', 'columns' => ['event_id', 'experiment', 'goal']],
    ];

    private const string NOW = '2026-06-11 12:10:00';

    private InMemoryStorage $storage;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = new InMemoryStorage();
    }

    public function returnsEmptyResultWhenNothingPending(): void
    {
        $result = $this->exporter(new RecordingWriterFactory())->export();

        Assert::same($result->totalHandled(), 0);
        Assert::same($result->groupCount(), 0);
        Assert::same($result->skipped, 0);
    }

    public function batchesOneTypeIntoOneGroupAndMarksPublished(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $this->storage->save($this->pending(id: 'b', type: 'ab.exposure', payload: '{"experiment":"y"}'));
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory)->export();

        Assert::same($result->published, 2);
        Assert::same($result->groupCount(), 1);
        Assert::count($factory->created, 1);
        Assert::same($factory->created[0]['table'], 'ab_exposures');
        Assert::same($factory->writers['ab_exposures']->rows, [
            ['event_id' => 'a', 'experiment' => 'x'],
            ['event_id' => 'b', 'experiment' => 'y'],
        ]);
        Assert::same($this->storage->findPending(), []);
        $first = $this->storage->getById('a');
        $second = $this->storage->getById('b');
        Assert::notNull($first);
        Assert::notNull($second);
        Assert::same($first->getStatus(), OutboxStatus::Published);
        Assert::same($second->getStatus(), OutboxStatus::Published);
    }

    public function exportOrFailReturnsResultWhenBatchSucceeds(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));

        $result = $this->exporter(new RecordingWriterFactory())->exportOrFail();

        Assert::same($result->published, 1);
        Assert::false($result->hasFailures());
    }

    public function splitsDifferentTypesIntoSeparateGroups(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $this->storage->save($this->pending(id: 'b', type: 'ab.conversion', payload: '{"experiment":"x","goal":"buy"}'));
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory)->export();

        Assert::same($result->published, 2);
        Assert::same($result->groupCount(), 2);
        Assert::count($factory->created, 2);
        Assert::count($factory->writers['ab_exposures']->rows, 1);
        Assert::count($factory->writers['ab_conversions']->rows, 1);
    }

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

        Assert::same($result->skipped, 1);
        Assert::same($result->totalHandled(), 0);
        Assert::same($factory->created, []);
        $message = $this->storage->getById('a');
        Assert::notNull($message);
        Assert::same($message->getStatus(), OutboxStatus::Pending);
    }

    public function terminalRouteFailureMarksMessageFailed(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{}'));
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory)->export();

        Assert::same($result->terminalFailed, 1);
        Assert::same($result->published, 0);
        Assert::same($factory->created, []);
        $message = $this->storage->getById('a');
        Assert::notNull($message);
        Assert::same($message->getStatus(), OutboxStatus::Failed);
    }

    public function retryableWriteFailureKeepsMessagePendingWithIncrementedAttempts(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $factory = new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')]);

        $result = $this->exporter($factory)->export();

        Assert::same($result->retryScheduled, 1);
        Assert::same($result->published, 0);
        Assert::true($result->hasFailures());
        $message = $this->storage->getById('a');
        Assert::notNull($message);
        Assert::same($message->getStatus(), OutboxStatus::Pending);
        Assert::same($message->getAttempts(), 1);
    }

    public function exportOrFailThrowsWithResultWhenBatchHasFailures(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $factory = new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')]);

        try {
            $this->exporter($factory)->exportOrFail();
            Assert::fail('Expected ClickHouseExportException to be thrown');
        } catch (ClickHouseExportException $e) {
            Assert::same($e->getResult()->retryScheduled, 1);
            Assert::same($e->getResult()->terminalFailed, 0);
            Assert::same(
                $e->getMessage(),
                'ClickHouse export reported failures: 1 retry scheduled, 0 terminal failed',
            );
        }
    }

    public function fetchLimitScopesThePoll(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->storage->save($this->pending(id: 'm' . $i, type: 'ab.exposure', payload: '{"experiment":"x"}'));
        }
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory)->export(limit: 2);

        Assert::same($result->published, 2);
        Assert::count($this->storage->findPending(), 3);
    }

    public function rejectsNonPositiveFetchLimit(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->exporter(new RecordingWriterFactory(), fetchLimit: 0);
    }

    public function allowsFetchLimitOfOne(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));

        $result = $this->exporter(new RecordingWriterFactory(), fetchLimit: 1)->export();

        Assert::same($result->published, 1);
    }

    public function skipsNotReadyMessageThatPrecedesAReadyOne(): void
    {
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

        Assert::same($result->skipped, 1);
        Assert::same($result->published, 1);
        Assert::same($factory->writers['ab_exposures']->rows, [['event_id' => 'ready', 'experiment' => 'y']]);
    }

    public function logsRouteFailureWithContext(): void
    {
        $this->storage->save($this->pending(id: 'bad', type: 'ab.exposure', payload: '{}'));
        $logged = [];
        $logger = new class ($logged) implements LoggerInterface {
            public function __construct(private array &$logged) {}

            public function emergency(string|\Stringable $message, array $context = []): void {}

            public function alert(string|\Stringable $message, array $context = []): void {}

            public function critical(string|\Stringable $message, array $context = []): void {}

            public function error(string|\Stringable $message, array $context = []): void {}

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->logged[] = ['message' => $message, 'context' => $context];
            }

            public function notice(string|\Stringable $message, array $context = []): void {}

            public function info(string|\Stringable $message, array $context = []): void {}

            public function debug(string|\Stringable $message, array $context = []): void {}

            public function log(mixed $level, string|\Stringable $message, array $context = []): void {}
        };

        $this->exporter(new RecordingWriterFactory(), logger: $logger)->export();

        Assert::count($logged, 1);
        Assert::same($logged[0]['message'], 'ClickHouse outbox route failed');
        Assert::same($logged[0]['context']['messageId'], 'bad');
        Assert::same($logged[0]['context']['type'], 'ab.exposure');
        Assert::true(is_string($logged[0]['context']['error']));
    }

    public function retryableRouteFailureSchedulesRetryAndKeepsMessagePending(): void
    {
        $this->storage->save($this->pending(id: 'first-bad', type: 'ab.exposure', payload: '{}'));
        $this->storage->save($this->pending(id: 'good', type: 'ab.exposure', payload: '{"experiment":"x"}'));

        $result = $this->exporter(new RecordingWriterFactory(), decider: $this->alwaysRetryable())->export();

        Assert::same($result->retryScheduled, 1);
        Assert::same($result->published, 1);
        $bad = $this->storage->getById('first-bad');
        Assert::notNull($bad);
        Assert::same($bad->getStatus(), OutboxStatus::Pending);
    }

    public function successfulGroupResultReportsZeroFailures(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));

        $result = $this->exporter(new RecordingWriterFactory())->export();

        Assert::count($result->groups, 1);
        Assert::same($result->groups[0]->published, 1);
        Assert::same($result->groups[0]->retryScheduled, 0);
        Assert::same($result->groups[0]->terminalFailed, 0);
        Assert::same($result->groups[0]->messageCount, 1);
    }

    public function terminalWriteFailureMarksMessageFailed(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $factory = new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')]);

        $result = $this->exporter($factory, decider: $this->alwaysTerminal())->export();

        Assert::same($result->terminalFailed, 1);
        Assert::same($result->retryScheduled, 0);
        Assert::same($result->groups[0]->terminalFailed, 1);
        $message = $this->storage->getById('a');
        Assert::notNull($message);
        Assert::same($message->getStatus(), OutboxStatus::Failed);
    }

    public function logsGroupFailureWithContext(): void
    {
        $this->storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $factory = new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')]);
        $logged = [];
        $logger = new class ($logged) implements LoggerInterface {
            public function __construct(private array &$logged) {}

            public function emergency(string|\Stringable $message, array $context = []): void {}

            public function alert(string|\Stringable $message, array $context = []): void {}

            public function critical(string|\Stringable $message, array $context = []): void {}

            public function error(string|\Stringable $message, array $context = []): void {}

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->logged[] = ['message' => $message, 'context' => $context];
            }

            public function notice(string|\Stringable $message, array $context = []): void {}

            public function info(string|\Stringable $message, array $context = []): void {}

            public function debug(string|\Stringable $message, array $context = []): void {}

            public function log(mixed $level, string|\Stringable $message, array $context = []): void {}
        };

        $this->exporter($factory, logger: $logger)->export();

        Assert::count($logged, 1);
        Assert::same($logged[0]['message'], 'ClickHouse outbox export group failed');
        Assert::same($logged[0]['context']['table'], 'ab_exposures');
        Assert::same($logged[0]['context']['messageCount'], 1);
        Assert::true(is_string($logged[0]['context']['error']));
    }

    public function accumulatesRetryAndTerminalAcrossGroups(): void
    {
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

        Assert::same($result->retryScheduled, 1);
        Assert::same($result->terminalFailed, 1);
        Assert::same($result->published, 0);
        Assert::same($result->groups[0]->terminalFailed, 1);
        Assert::same($result->groups[1]->terminalFailed, 0);
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
        ClickHouseWriterFactoryInterface $factory,
        ?FailureDeciderInterface $decider = null,
        ?LoggerInterface $logger = null,
        int $fetchLimit = 1000,
    ): ClickHouseOutboxExporter {
        $now = self::NOW;
        $clock = new class ($now) implements ClockInterface {
            public function __construct(private readonly string $now) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->now);
            }
        };

        return new ClickHouseOutboxExporter(
            storage: $this->storage,
            router: new MapClickHouseMessageRouter(routes: self::ROUTES),
            retryPolicy: new RetryPolicy(maxAttempts: 3, delaySeconds: 30),
            clock: $clock,
            writerFactory: $factory,
            failureDecider: $decider ?? new DefaultFailureDecider(),
            fetchLimit: $fetchLimit,
            logger: $logger ?? new \Psr\Log\NullLogger(),
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
