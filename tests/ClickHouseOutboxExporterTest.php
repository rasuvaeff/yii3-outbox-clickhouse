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
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseWriterFactoryInterface;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultFailureDecider;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseExportException;
use Rasuvaeff\Yii3OutboxClickHouse\FailureDeciderInterface;
use Rasuvaeff\Yii3OutboxClickHouse\FailureDecision;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\RecordingLogger;
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

        foreach ($specs as $index => $spec) {
            if ($spec['type'] === 'ab.exposure') {
                $handledCount++;
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
        Classify::cover($result->skipped > 0, 'skipped something', 10.0);
        Classify::when($specs === [], 'empty batch');

        Assert::same(
            $result->published + $result->retryScheduled + $result->terminalFailed + $result->skipped,
            $handledCount,
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
