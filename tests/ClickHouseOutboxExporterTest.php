<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseWriterFactoryInterface;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultFailureDecider;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseExportException;
use Rasuvaeff\Yii3OutboxClickHouse\FailureDeciderInterface;
use Rasuvaeff\Yii3OutboxClickHouse\FailureDecision;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\BatchAcknowledgingStorage;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\FlakyStorage;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\PlainFlakyStorage;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\PlainStorage;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\RecordingLogger;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\RecordingWriterFactory;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\StorageDown;
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

    public function acknowledgesEachGroupWithOneBatchCall(): void
    {
        $storage = new BatchAcknowledgingStorage();
        $storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $storage->save($this->pending(id: 'b', type: 'ab.exposure', payload: '{"experiment":"y"}'));
        $storage->save($this->pending(id: 'c', type: 'ab.conversion', payload: '{"experiment":"x","goal":"buy"}'));

        $result = $this->exporter(new RecordingWriterFactory(), storage: $storage)->export();

        Assert::same($result->published, 3);
        Assert::same($storage->batches, [['a', 'b'], ['c']]);
        Assert::same($storage->acknowledged, []);
        Assert::same($storage->getById('a')?->getStatus(), OutboxStatus::Published);
        Assert::same($storage->getById('c')?->getStatus(), OutboxStatus::Published);
    }

    public function acknowledgesOneMessageAtATimeOnAPlainStorage(): void
    {
        $storage = new PlainStorage();
        $storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $storage->save($this->pending(id: 'b', type: 'ab.exposure', payload: '{"experiment":"y"}'));

        $result = $this->exporter(new RecordingWriterFactory(), storage: $storage)->export();

        Assert::same($result->published, 2);
        Assert::same($storage->acknowledged, ['a', 'b']);
        Assert::same($storage->getById('a')?->getStatus(), OutboxStatus::Published);
        Assert::same($storage->getById('b')?->getStatus(), OutboxStatus::Published);
    }

    public function aFailedGroupIsNotAcknowledgedAsABatch(): void
    {
        $storage = new BatchAcknowledgingStorage();
        $storage->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $storage->save($this->pending(id: 'b', type: 'ab.exposure', payload: '{"experiment":"y"}'));
        $factory = new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('boom')]);

        $result = $this->exporter($factory, storage: $storage)->export();

        Assert::same($result->published, 0);
        Assert::same($result->retryScheduled, 2);
        Assert::same($storage->batches, []);
        Assert::same($storage->acknowledged, []);
        Assert::same($storage->getById('a')?->getStatus(), OutboxStatus::Pending);
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

    /**
     * An exhausted message keeps arriving even inside its backoff window — it
     * is the one thing `claimReady()` must not filter out, since nothing but
     * `markFailed()` terminates it. Failing it must not end the batch: the
     * messages behind it still have to be exported.
     */
    public function anExhaustedMessageDoesNotAbortTheRestOfTheBatch(): void
    {
        $this->storage->save($this->pending(
            id: 'exhausted',
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            attempts: 3,
            lastAttemptAt: new \DateTimeImmutable(self::NOW),
        ));
        $this->storage->save($this->pending(id: 'ready', type: 'ab.exposure', payload: '{"experiment":"y"}'));
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory)->export();

        Assert::same($result->terminalFailed, 1);
        Assert::same($result->published, 1);
        Assert::same($this->storage->getById('exhausted')?->getStatus(), OutboxStatus::Failed);
        Assert::same($factory->writers['ab_exposures']->rows, [['event_id' => 'ready', 'experiment' => 'y']]);
    }

    /**
     * A retry-aware storage never hands over a message still waiting out its
     * backoff, so there is nothing to skip and nothing to write back.
     */
    public function neverClaimsMessagesNotReadyForRetry(): void
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

        Assert::same($result->skipped, 0);
        Assert::same($result->totalHandled(), 0);
        Assert::same($factory->created, []);
        $message = $this->storage->getById('a');
        Assert::notNull($message);
        Assert::same($message->getStatus(), OutboxStatus::Pending);
        Assert::same($message->getAttempts(), 1);
    }

    /**
     * The fallback path, reached through a storage that cannot apply the
     * predicate itself: the message is claimed, discarded in PHP and written
     * back as `Pending`.
     */
    public function skipsMessagesNotReadyForRetryOnAPlainStorage(): void
    {
        $storage = new PlainStorage();
        $storage->save($this->pending(
            id: 'a',
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            attempts: 1,
            lastAttemptAt: new \DateTimeImmutable(self::NOW),
        ));
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory, storage: $storage)->export();

        Assert::same($result->skipped, 1);
        Assert::same($result->totalHandled(), 0);
        Assert::same($factory->created, []);
        Assert::same($storage->getById('a')?->getStatus(), OutboxStatus::Pending);
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

    public function skipsNotReadyMessageThatPrecedesAReadyOneOnAPlainStorage(): void
    {
        $storage = new PlainStorage();
        $storage->save($this->pending(
            id: 'not-ready',
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            attempts: 1,
            lastAttemptAt: new \DateTimeImmutable(self::NOW),
        ));
        $storage->save($this->pending(id: 'ready', type: 'ab.exposure', payload: '{"experiment":"y"}'));
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory, storage: $storage)->export();

        Assert::same($result->skipped, 1);
        Assert::same($result->published, 1);
        Assert::same($factory->writers['ab_exposures']->rows, [['event_id' => 'ready', 'experiment' => 'y']]);
    }

    /**
     * The point of the pushdown: with `fetchLimit` down to one slot, the
     * backing-off message no longer occupies it, so the ready one is exported
     * on this run instead of waiting for the retry queue to drain.
     */
    public function aBackingOffMessageDoesNotConsumeTheFetchLimit(): void
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

        $result = $this->exporter($factory, fetchLimit: 1)->export();

        Assert::same($result->skipped, 0);
        Assert::same($result->published, 1);
        Assert::same($factory->writers['ab_exposures']->rows, [['event_id' => 'ready', 'experiment' => 'y']]);
        Assert::same($this->storage->getById('not-ready')?->getStatus(), OutboxStatus::Pending);
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

    public function exhaustedAttemptsAreTerminatedInsteadOfSkipped(): void
    {
        // attempts == maxAttempts: not ready for retry, and never will be. The
        // old code saved it back as Pending on every single run.
        $this->storage->save($this->pending(
            id: 'a',
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            attempts: 3,
            lastAttemptAt: new \DateTimeImmutable('2026-06-11 12:00:00'),
        ));
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory)->export();

        Assert::same($result->terminalFailed, 1);
        Assert::same($result->skipped, 0);
        Assert::same($factory->created, []);
        $message = $this->storage->getById('a');
        Assert::notNull($message);
        Assert::same($message->getStatus(), OutboxStatus::Failed);
        Assert::same($message->getAttempts(), 3);
    }

    public function retryableWriteFailureOnTheLastAttemptTerminates(): void
    {
        // attempts 2 of 3: this run spends the last one, so the retryable
        // verdict from the decider must be capped into a terminal one.
        $this->storage->save($this->pending(
            id: 'a',
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            attempts: 2,
            lastAttemptAt: new \DateTimeImmutable('2026-06-11 12:00:00'),
        ));
        $factory = new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')]);

        $result = $this->exporter($factory)->export();

        Assert::same($result->terminalFailed, 1);
        Assert::same($result->retryScheduled, 0);
        Assert::true($result->hasTerminalFailures());
        $message = $this->storage->getById('a');
        Assert::notNull($message);
        Assert::same($message->getStatus(), OutboxStatus::Failed);
        Assert::same($message->getAttempts(), 3);
    }

    public function retryableRouteFailureOnTheLastAttemptTerminates(): void
    {
        $this->storage->save($this->pending(
            id: 'a',
            type: 'ab.exposure',
            payload: '{}',
            attempts: 2,
            lastAttemptAt: new \DateTimeImmutable('2026-06-11 12:00:00'),
        ));
        $factory = new RecordingWriterFactory();

        $result = $this->exporter($factory, decider: $this->alwaysRetryable())->export();

        Assert::same($result->terminalFailed, 1);
        Assert::same($result->retryScheduled, 0);
        $message = $this->storage->getById('a');
        Assert::notNull($message);
        Assert::same($message->getStatus(), OutboxStatus::Failed);
    }

    public function logsRetryExhaustionWithContext(): void
    {
        $logger = new RecordingLogger();

        $this->storage->save($this->pending(
            id: 'a',
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            attempts: 3,
            lastAttemptAt: new \DateTimeImmutable('2026-06-11 12:00:00'),
        ));

        $this->exporter(new RecordingWriterFactory(), logger: $logger)->export();

        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['message'], 'ClickHouse outbox message exhausted its retries');
        Assert::same($logger->records[0]['context'], [
            'messageId' => 'a',
            'type' => 'ab.exposure',
            'attempts' => 3,
        ]);
    }

    public function logsRetryExhaustionCausedByAWriteFailureWithTheError(): void
    {
        $logger = new RecordingLogger();

        $this->storage->save($this->pending(
            id: 'a',
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            attempts: 2,
            lastAttemptAt: new \DateTimeImmutable('2026-06-11 12:00:00'),
        ));
        $factory = new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')]);

        $this->exporter($factory, logger: $logger)->export();

        $exhaustion = array_values(array_filter(
            $logger->records,
            static fn(array $record): bool => $record['message'] === 'ClickHouse outbox message exhausted its retries',
        ));

        Assert::count($exhaustion, 1);
        Assert::same($exhaustion[0]['context'], [
            'messageId' => 'a',
            'type' => 'ab.exposure',
            'attempts' => 3,
            'error' => 'down',
        ]);
    }

    /**
     * The invariant the exporter exists to keep: after a run, a message is
     * either published, waiting for a retry it can still make, or Failed.
     * "Pending with no attempts left" is the zombie state that made a
     * ClickHouse outage longer than maxAttempts x delaySeconds strand the whole
     * backlog invisibly — a single-scenario test cannot cover the product of
     * attempt counts, due times, routability and writer outcomes.
     *
     * @param list<array{type: string, routable: bool, attempts: int, dueOffset: int}> $specs
     */
    #[Property(runs: 200, timeoutMs: 5000)]
    public function everyRunLeavesEachMessagePublishedRetryableOrFailed(array $specs, string $writerBehaviour): void
    {
        $this->storage = new InMemoryStorage();

        $handledCount = 0;
        // Exactly what claimReady() lets through: never attempted, past the
        // 30-second backoff, or out of attempts (which must keep arriving —
        // nothing but markFailed() terminates one).
        $claimable = 0;

        foreach ($specs as $index => $spec) {
            if ($spec['type'] === 'ab.exposure') {
                $handledCount++;

                if ($spec['attempts'] === 0 || $spec['attempts'] >= 3 || $spec['dueOffset'] >= 30) {
                    $claimable++;
                }
            }

            $this->storage->save($this->pending(
                id: 'm' . $index,
                type: $spec['type'],
                payload: $spec['routable'] ? '{"experiment":"x"}' : '{}',
                attempts: $spec['attempts'],
                lastAttemptAt: $spec['attempts'] === 0
                    ? null
                    : (new \DateTimeImmutable(self::NOW))->modify('-' . $spec['dueOffset'] . ' seconds'),
            ));
        }

        $factory = $writerBehaviour === 'transient'
            ? new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')])
            : new RecordingWriterFactory();

        $result = $this->exporter($factory)->export();

        // Each outcome must actually occur across the random phase, or the
        // invariant below is only checked on the paths that happen to be cheap
        // to generate. Measured over 400 runs: published 21%, retry 18%,
        // terminal 74%, skipped 30%.
        Classify::cover($result->published > 0, 'published something', 5.0);
        Classify::cover($result->retryScheduled > 0, 'scheduled a retry', 5.0);
        Classify::cover($result->terminalFailed > 0, 'terminated something', 20.0);
        Classify::cover($claimable < $handledCount, 'left a message in backoff unclaimed', 10.0);
        Classify::when($specs === [], 'empty batch');

        // The storage is retry-aware, so a handled message still inside its
        // backoff window is never claimed and never counted. `skipped` is
        // therefore structurally zero: the work it used to count is exactly
        // what the readiness pushdown removes.
        Assert::same($result->skipped, 0);
        Assert::same(
            $result->published + $result->retryScheduled + $result->terminalFailed + $result->skipped,
            $claimable,
        );

        foreach ($specs as $index => $spec) {
            $message = $this->storage->getById('m' . $index);
            Assert::notNull($message);

            // Claimed rows are never left mid-flight.
            Assert::true($message->getStatus() !== OutboxStatus::Processing);

            if ($spec['type'] !== 'ab.exposure') {
                // Foreign types are out of this exporter's scope: never
                // claimed, so status and attempts are untouched — including a
                // foreign message that has itself run out of attempts, which is
                // its own consumer's business.
                Assert::same($message->getStatus(), OutboxStatus::Pending);
                Assert::same($message->getAttempts(), $spec['attempts']);

                continue;
            }

            // The zombie state: Pending with nothing left to spend. Such a
            // message would be re-claimed and skipped on every run forever.
            if ($message->getStatus() === OutboxStatus::Pending) {
                Assert::true($message->getAttempts() < 3);
            }
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function everyRunLeavesEachMessagePublishedRetryableOrFailedGenerators(): array
    {
        return [
            'specs' => Gen::arrayOf(
                Gen::record([
                    // One handled type and one nobody routes: the second must
                    // never be claimed, let alone failed.
                    'type' => Gen::elements(['ab.exposure', 'other.type']),
                    'routable' => Gen::bool(),
                    'attempts' => Gen::intBetween(0, 4),
                    // delaySeconds is 30, so 0 and 10 are "not due yet".
                    'dueOffset' => Gen::elements([0, 10, 45, 90]),
                ]),
                maxSize: 6,
            ),
            'writerBehaviour' => Gen::elements(['ok', 'transient']),
        ];
    }

    /** @return iterable<string, array{list<array{type: string, routable: bool, attempts: int, dueOffset: int}>, string}> */
    public static function everyRunLeavesEachMessagePublishedRetryableOrFailedExamples(): iterable
    {
        yield 'exhausted message with a healthy writer' => [
            [['type' => 'ab.exposure', 'routable' => true, 'attempts' => 3, 'dueOffset' => 90]],
            'ok',
        ];
        yield 'last attempt spent by a ClickHouse outage' => [
            [['type' => 'ab.exposure', 'routable' => true, 'attempts' => 2, 'dueOffset' => 90]],
            'transient',
        ];
        yield 'foreign type is never claimed' => [
            [['type' => 'other.type', 'routable' => true, 'attempts' => 0, 'dueOffset' => 0]],
            'transient',
        ];
        yield 'empty outbox' => [[], 'ok'];
    }

    // --- storage failure mid-batch --------------------------------------

    /**
     * @return array{0: FlakyStorage, 1: InMemoryStorage}
     */
    private function flaky(string $failing, int $failures = 1): array
    {
        $inner = new InMemoryStorage();

        return [new FlakyStorage($inner, $failing, $failures), $inner];
    }

    private function statusOf(InMemoryStorage $storage, string $id): OutboxStatus
    {
        $message = $storage->getById($id);
        Assert::notNull($message);

        return $message->getStatus();
    }

    public function aFailingMarkFailedReleasesTheRestOfTheBatch(): void
    {
        [$storage, $inner] = $this->flaky(FlakyStorage::MARK_FAILED);
        $inner->save($this->pending(id: 'spent', type: 'ab.exposure', payload: '{"experiment":"x"}', attempts: 3));
        $inner->save($this->pending(id: 'fresh', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $inner->save($this->pending(id: 'other-spent', type: 'ab.conversion', payload: '{"experiment":"x","goal":"buy"}', attempts: 3));
        $factory = new RecordingWriterFactory();

        $thrown = null;

        try {
            $this->exporter($factory, storage: $storage)->export();
        } catch (StorageDown $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getMessage(), 'markFailed() failed');
        // Nothing reached ClickHouse: the batch aborted before any group.
        Assert::same($factory->created, []);
        // The message whose write failed was retried by the release and
        // the storage was back by then.
        Assert::same($this->statusOf($inner, 'spent'), OutboxStatus::Failed);
        Assert::same($this->statusOf($inner, 'fresh'), OutboxStatus::Pending);
        Assert::same($inner->getById('fresh')?->getAttempts(), 0);
        Assert::same($this->statusOf($inner, 'other-spent'), OutboxStatus::Failed);
    }

    public function aFailingSaveOfANotReadyMessageReleasesTheRestOfTheBatch(): void
    {
        $inner = new InMemoryStorage();
        $storage = new PlainFlakyStorage(new FlakyStorage($inner, FlakyStorage::SAVE));
        $inner->save($this->pending(
            id: 'backing-off',
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            attempts: 1,
            lastAttemptAt: (new \DateTimeImmutable(self::NOW))->modify('-5 seconds'),
        ));
        $inner->save($this->pending(id: 'fresh', type: 'ab.exposure', payload: '{"experiment":"x"}'));

        $thrown = null;

        try {
            $this->exporter(new RecordingWriterFactory(), storage: $storage)->export();
        } catch (StorageDown $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getMessage(), 'save() failed');
        Assert::same($this->statusOf($inner, 'backing-off'), OutboxStatus::Pending);
        Assert::same($inner->getById('backing-off')?->getAttempts(), 1);
        Assert::same($this->statusOf($inner, 'fresh'), OutboxStatus::Pending);
        Assert::same($inner->getById('fresh')?->getAttempts(), 0);
    }

    public function aFailingBatchAcknowledgementPutsTheWrittenGroupBackAsPending(): void
    {
        [$storage, $inner] = $this->flaky(FlakyStorage::MARK_PUBLISHED_BATCH);
        $inner->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $inner->save($this->pending(id: 'b', type: 'ab.exposure', payload: '{"experiment":"y"}'));
        $inner->save($this->pending(id: 'c', type: 'ab.conversion', payload: '{"experiment":"x","goal":"buy"}'));
        $factory = new RecordingWriterFactory();

        $thrown = null;

        try {
            $this->exporter($factory, storage: $storage)->export();
        } catch (StorageDown $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getMessage(), 'markPublishedBatch() failed');
        // The first group's rows reached ClickHouse; the second group
        // was never written.
        Assert::count($factory->created, 1);
        Assert::same($factory->created[0]['table'], 'ab_exposures');
        // Written but unacknowledged: back to Pending with the attempt
        // recorded — at-least-once, deduplicated by the event id.
        Assert::same($this->statusOf($inner, 'a'), OutboxStatus::Pending);
        Assert::same($inner->getById('a')?->getAttempts(), 1);
        Assert::same($this->statusOf($inner, 'b'), OutboxStatus::Pending);
        Assert::same($inner->getById('b')?->getAttempts(), 1);
        // Never attempted: released as it was claimed.
        Assert::same($this->statusOf($inner, 'c'), OutboxStatus::Pending);
        Assert::same($inner->getById('c')?->getAttempts(), 0);
        Assert::same($storage->writes, ['markPublishedBatch', 'save', 'save', 'save']);
    }

    public function anAcknowledgedGroupStaysPublishedWhenALaterGroupAborts(): void
    {
        $inner = new InMemoryStorage();
        $storage = new FlakyStorage($inner, FlakyStorage::MARK_PUBLISHED_BATCH, after: 1);
        $inner->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $inner->save($this->pending(id: 'c', type: 'ab.conversion', payload: '{"experiment":"x","goal":"buy"}'));

        $thrown = null;

        try {
            $this->exporter(new RecordingWriterFactory(), storage: $storage)->export();
        } catch (StorageDown $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        // The first group was acknowledged before the second one's
        // acknowledgement failed: the release must not touch it, or a
        // delivered message would be delivered again.
        Assert::same($this->statusOf($inner, 'a'), OutboxStatus::Published);
        Assert::same($this->statusOf($inner, 'c'), OutboxStatus::Pending);
        Assert::same($storage->writes, ['markPublishedBatch', 'markPublishedBatch', 'save']);
    }

    public function aFailingPerMessageAcknowledgementPutsTheWholeGroupBackAsPending(): void
    {
        $inner = new InMemoryStorage();
        $flaky = new FlakyStorage($inner, FlakyStorage::MARK_PUBLISHED);
        $storage = new PlainFlakyStorage($flaky);
        $inner->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $inner->save($this->pending(id: 'b', type: 'ab.exposure', payload: '{"experiment":"y"}'));

        $thrown = null;

        try {
            $this->exporter(new RecordingWriterFactory(), storage: $storage)->export();
        } catch (StorageDown $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getMessage(), 'markPublished() failed');
        // 'a' failed to acknowledge, so 'b' was never tried: both are
        // released with the attempt they spent.
        Assert::same($this->statusOf($inner, 'a'), OutboxStatus::Pending);
        Assert::same($this->statusOf($inner, 'b'), OutboxStatus::Pending);
        Assert::same($inner->getById('a')?->getAttempts(), 1);
        Assert::same($inner->getById('b')?->getAttempts(), 1);
        Assert::same($flaky->writes, ['markPublished', 'save', 'save']);
    }

    public function aStorageFailureWhilePersistingAWriteFailurePropagatesAndReleases(): void
    {
        [$storage, $inner] = $this->flaky(FlakyStorage::SAVE);
        $inner->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $inner->save($this->pending(id: 'b', type: 'ab.exposure', payload: '{"experiment":"y"}'));
        $inner->save($this->pending(id: 'c', type: 'ab.conversion', payload: '{"experiment":"x","goal":"buy"}'));
        $factory = new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')]);

        // ClickHouse is down AND the storage fails to record that: the caller
        // sees the storage failure, not the ClickHouse one — the decider only
        // rules on the latter.
        $thrown = null;

        try {
            $this->exporter($factory, storage: $storage)->export();
        } catch (StorageDown $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getMessage(), 'save() failed');
        Assert::same($this->statusOf($inner, 'a'), OutboxStatus::Pending);
        Assert::same($inner->getById('a')?->getAttempts(), 1);
        Assert::same($this->statusOf($inner, 'b'), OutboxStatus::Pending);
        Assert::same($this->statusOf($inner, 'c'), OutboxStatus::Pending);
        Assert::same($inner->getById('c')?->getAttempts(), 0);
    }

    public function aStorageFailureWhilePersistingARouteFailurePropagatesAndReleases(): void
    {
        [$storage, $inner] = $this->flaky(FlakyStorage::SAVE);
        $inner->save($this->pending(id: 'unroutable', type: 'ab.exposure', payload: '{}'));
        $inner->save($this->pending(id: 'fresh', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $decider = new class implements FailureDeciderInterface {
            public function decide(OutboxMessage $message, \Throwable $e): FailureDecision
            {
                return FailureDecision::Retryable;
            }
        };

        $thrown = null;

        try {
            $this->exporter(new RecordingWriterFactory(), decider: $decider, storage: $storage)->export();
        } catch (StorageDown $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getMessage(), 'save() failed');
        Assert::same($this->statusOf($inner, 'unroutable'), OutboxStatus::Pending);
        Assert::same($inner->getById('unroutable')?->getAttempts(), 1);
        Assert::same($this->statusOf($inner, 'fresh'), OutboxStatus::Pending);
        Assert::same($inner->getById('fresh')?->getAttempts(), 0);
    }

    public function theReleaseTerminatesAMessageThatArrivedWithNoAttemptsLeft(): void
    {
        [$storage, $inner] = $this->flaky(FlakyStorage::MARK_PUBLISHED_BATCH);
        $inner->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}', attempts: 2));
        $inner->save($this->pending(id: 'spent', type: 'ab.conversion', payload: '{"experiment":"x","goal":"buy"}', attempts: 3));
        $logger = new RecordingLogger();

        $thrown = null;

        try {
            $this->exporter(new RecordingWriterFactory(), logger: $logger, storage: $storage)->export();
        } catch (StorageDown $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        // 'a' spent its third and last attempt on a write that reached
        // ClickHouse but was not acknowledged: nothing left to spend, so
        // the release terminates it rather than saving a zombie Pending.
        Assert::same($this->statusOf($inner, 'a'), OutboxStatus::Failed);
        Assert::same($this->statusOf($inner, 'spent'), OutboxStatus::Failed);
        Assert::same(
            array_map(static fn(array $r): string => $r['message'], $logger->records),
            ['ClickHouse outbox message exhausted its retries', 'ClickHouse outbox message exhausted its retries'],
        );
        // 'spent' was terminated by the loop before any group ran; 'a'
        // by the release.
        Assert::same($logger->records[0]['context'], ['messageId' => 'spent', 'type' => 'ab.conversion', 'attempts' => 3]);
        Assert::same($logger->records[1]['context'], ['messageId' => 'a', 'type' => 'ab.exposure', 'attempts' => 3]);
    }

    public function aReleaseThatFailsIsLoggedAndTheOriginalExceptionStillPropagates(): void
    {
        [$storage, $inner] = $this->flaky(FlakyStorage::SAVE, failures: PHP_INT_MAX);
        $inner->save($this->pending(
            id: 'backing-off',
            type: 'ab.exposure',
            payload: '{"experiment":"x"}',
            attempts: 1,
            lastAttemptAt: (new \DateTimeImmutable(self::NOW))->modify('-5 seconds'),
        ));
        $inner->save($this->pending(id: 'fresh', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $inner->save($this->pending(id: 'spent', type: 'ab.exposure', payload: '{"experiment":"x"}', attempts: 3));
        $plain = new PlainFlakyStorage($storage);
        $logger = new RecordingLogger();

        $thrown = null;

        try {
            $this->exporter(new RecordingWriterFactory(), logger: $logger, storage: $plain)->export();
        } catch (StorageDown $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getMessage(), 'save() failed');
        // A storage that never recovers cannot be told anything: the
        // rows stay Processing, and each failed release is one error line
        // — the exception the caller gets is still the first one.
        Assert::same($this->statusOf($inner, 'backing-off'), OutboxStatus::Processing);
        Assert::same($this->statusOf($inner, 'fresh'), OutboxStatus::Processing);
        // markFailed() works, so the exhausted one is terminated even now.
        Assert::same($this->statusOf($inner, 'spent'), OutboxStatus::Failed);
        $errors = array_values(array_filter($logger->records, static fn(array $r): bool => $r['level'] === 'error'));
        Assert::count($errors, 2);
        Assert::same($errors[0]['message'], 'Failed to release a claimed ClickHouse outbox message');
        Assert::same($errors[0]['context'], [
            'messageId' => 'backing-off',
            'type' => 'ab.exposure',
            'exception' => StorageDown::class,
            'error' => 'save() failed',
        ]);
        Assert::same($errors[1]['context']['messageId'], 'fresh');
    }

    public function aSuccessfulRunPerformsNoRelease(): void
    {
        [$storage, $inner] = $this->flaky(FlakyStorage::SAVE, failures: 0);
        $inner->save($this->pending(id: 'a', type: 'ab.exposure', payload: '{"experiment":"x"}'));
        $inner->save($this->pending(id: 'spent', type: 'ab.conversion', payload: '{"experiment":"x","goal":"buy"}', attempts: 3));

        $result = $this->exporter(new RecordingWriterFactory(), storage: $storage)->export();

        Assert::same($result->published, 1);
        Assert::same($result->terminalFailed, 1);
        Assert::same($storage->writes, ['markFailed', 'markPublishedBatch']);
    }

    /**
     * Whichever write fails first — and whether or not the storage comes back
     * for the release — a batch never leaves a message in `Processing` that
     * the release could have moved: after one failure the storage recovers
     * and every claimed row is Pending, Published or Failed.
     *
     * @param list<array{attempts: int, routable: bool, type: string}> $specs
     */
    #[Property(runs: 400, timeoutMs: 5000)]
    public function anAbortedBatchLeavesNothingInProcessingOnceTheStorageIsBack(array $specs, string $failing, bool $clickHouseDown, bool $batchStorage): void
    {
        // 'acknowledge' is whichever acknowledgement this storage kind uses:
        // drawing the two methods separately would waste half the
        // acknowledgement runs on a method the exporter never calls.
        if ($failing === 'acknowledge') {
            $failing = $batchStorage ? FlakyStorage::MARK_PUBLISHED_BATCH : FlakyStorage::MARK_PUBLISHED;
        }

        $inner = new InMemoryStorage();
        $flaky = new FlakyStorage($inner, $failing);
        $storage = $batchStorage ? $flaky : new PlainFlakyStorage($flaky);

        foreach ($specs as $index => $spec) {
            $inner->save($this->pending(
                id: 'm' . $index,
                type: $spec['type'],
                payload: $spec['routable'] ? '{"experiment":"x"}' : '{}',
                attempts: $spec['attempts'],
                lastAttemptAt: $spec['attempts'] === 0 ? null : (new \DateTimeImmutable(self::NOW))->modify('-90 seconds'),
            ));
        }

        $factory = $clickHouseDown
            ? new RecordingWriterFactory(failTables: ['ab_exposures' => new ClickHouseWriteException('down')])
            : new RecordingWriterFactory();

        $aborted = false;

        try {
            $this->exporter($factory, storage: $storage)->export();
        } catch (StorageDown) {
            $aborted = true;
        }

        // Measured over 400 runs: aborted 20–27%, never reached 73–80%, on
        // save 6%, on markFailed 8%, on an acknowledgement 5.5%. Each gate is
        // about half its share, so a seed cannot trip it.
        Classify::cover($aborted, 'batch aborted', 10.0);
        Classify::cover(!$aborted, 'failing write never reached', 40.0);
        Classify::cover($aborted && $failing === FlakyStorage::SAVE, 'aborted on save', 3.0);
        Classify::cover($aborted && $failing === FlakyStorage::MARK_FAILED, 'aborted on markFailed', 3.0);
        Classify::cover($aborted && \in_array($failing, [FlakyStorage::MARK_PUBLISHED, FlakyStorage::MARK_PUBLISHED_BATCH], strict: true), 'aborted on acknowledgement', 2.5);

        foreach ($specs as $index => $spec) {
            $message = $inner->getById('m' . $index);
            Assert::notNull($message);
            Assert::true($message->getStatus() !== OutboxStatus::Processing);

            if ($spec['type'] !== 'ab.exposure') {
                // Never claimed, so never touched — not even by the release.
                Assert::same($message->getStatus(), OutboxStatus::Pending);
                Assert::same($message->getAttempts(), $spec['attempts']);

                continue;
            }

            if ($message->getStatus() === OutboxStatus::Pending) {
                // Never a zombie: Pending always has something left to spend.
                Assert::true($message->getAttempts() < 3);
            }
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function anAbortedBatchLeavesNothingInProcessingOnceTheStorageIsBackGenerators(): array
    {
        return [
            'specs' => Gen::arrayOf(
                Gen::record([
                    'type' => Gen::elements(['ab.exposure', 'other.type']),
                    'routable' => Gen::bool(),
                    'attempts' => Gen::intBetween(0, 3),
                ]),
                maxSize: 6,
            ),
            // save() is only reached through a retryable failure and an
            // acknowledgement only when ClickHouse is up, so both are drawn
            // more often than markFailed(); the gates above were measured
            // with this weighting.
            'failing' => Gen::frequency([
                [3, Gen::constant(FlakyStorage::SAVE)],
                [1, Gen::constant(FlakyStorage::MARK_FAILED)],
                [2, Gen::constant('acknowledge')],
            ]),
            'clickHouseDown' => Gen::bool(),
            'batchStorage' => Gen::bool(),
        ];
    }

    /** @return iterable<string, array{list<array{attempts: int, routable: bool, type: string}>, string, bool, bool}> */
    public static function anAbortedBatchLeavesNothingInProcessingOnceTheStorageIsBackExamples(): iterable
    {
        yield 'acknowledgement fails after the write' => [
            [['type' => 'ab.exposure', 'routable' => true, 'attempts' => 0], ['type' => 'ab.exposure', 'routable' => true, 'attempts' => 2]],
            'acknowledge',
            false,
            true,
        ];
        yield 'save fails while recording a ClickHouse outage' => [
            [['type' => 'ab.exposure', 'routable' => true, 'attempts' => 0], ['type' => 'ab.exposure', 'routable' => false, 'attempts' => 0]],
            FlakyStorage::SAVE,
            true,
            true,
        ];
        yield 'markFailed fails on an exhausted message ahead of a fresh one' => [
            [['type' => 'ab.exposure', 'routable' => true, 'attempts' => 3], ['type' => 'ab.exposure', 'routable' => true, 'attempts' => 0]],
            FlakyStorage::MARK_FAILED,
            false,
            false,
        ];
        yield 'empty batch' => [[], FlakyStorage::SAVE, false, true];
    }

    private function exporter(
        ClickHouseWriterFactoryInterface $factory,
        ?FailureDeciderInterface $decider = null,
        ?LoggerInterface $logger = null,
        int $fetchLimit = 1000,
        ?StorageInterface $storage = null,
    ): ClickHouseOutboxExporter {
        $now = self::NOW;
        $clock = new readonly class ($now) implements ClockInterface {
            public function __construct(private string $now) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->now);
            }
        };

        return new ClickHouseOutboxExporter(
            storage: $storage ?? $this->storage,
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
