<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseExportException;

/**
 * Reads pending outbox messages, routes and groups them by (table, columns), and
 * writes one batched insert per group to ClickHouse. Works directly against
 * {@see StorageInterface} (not the single-message `PublisherInterface`) so it can
 * batch and report per group.
 *
 * Retry/terminal semantics follow {@see RetryPolicy} and
 * {@see FailureDeciderInterface}: a group's write either publishes every message
 * or applies one decision to all of them (no per-row acknowledgement). ClickHouse
 * being unavailable never throws out of `export()` — those messages stay
 * `Pending` and retry later. At-least-once delivery means retries may insert a
 * row twice; pair the target table with `ReplacingMergeTree` keyed on the routed
 * event id (see {@see MapClickHouseMessageRouter}).
 *
 * @api
 */
final readonly class ClickHouseOutboxExporter
{
    public function __construct(
        private StorageInterface $storage,
        private ClickHouseMessageRouterInterface $router,
        private RetryPolicy $retryPolicy,
        private ClockInterface $clock,
        private ClickHouseWriterFactoryInterface $writerFactory,
        private FailureDeciderInterface $failureDecider = new DefaultFailureDecider(),
        private int $fetchLimit = 1000,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        if ($fetchLimit < 1) {
            throw new InvalidArgumentException(sprintf('Fetch limit must be at least 1, got %d', $fetchLimit));
        }
    }

    public function export(?int $limit = null): ClickHouseExportResult
    {
        $fetch = $limit ?? $this->fetchLimit;
        $now = $this->clock->now();

        $messages = $this->storage->claim($this->router->handledTypes(), $fetch);

        $published = 0;
        $retryScheduled = 0;
        $terminalFailed = 0;
        $skipped = 0;

        /** @var array<string, array{table: non-empty-string, columns: non-empty-list<string>, rows: list<array<string, mixed>>, messages: list<OutboxMessage>}> $groups */
        $groups = [];

        foreach ($messages as $message) {
            if (!$this->retryPolicy->isReadyForRetry($message, $now)) {
                $this->storage->save($message->withStatus(OutboxStatus::Pending));
                $skipped++;

                continue;
            }

            $message = $message->withAttempt($now);

            try {
                $route = $this->router->route($message);
            } catch (\Throwable $e) {
                $this->logger->warning('ClickHouse outbox route failed', [
                    'messageId' => $message->getId(),
                    'type' => $message->getType(),
                    'error' => $e->getMessage(),
                ]);

                if ($this->persistFailure($message, $e) === FailureDecision::Terminal) {
                    $terminalFailed++;
                } else {
                    $retryScheduled++;
                }

                continue;
            }

            $key = $route->groupKey();

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'table' => $route->table,
                    'columns' => $route->columns,
                    'rows' => [],
                    'messages' => [],
                ];
            }

            $groups[$key]['rows'][] = $route->row;
            $groups[$key]['messages'][] = $message;
        }

        $groupResults = [];

        foreach ($groups as $group) {
            $result = $this->exportGroup($group);
            $published += $result->published;
            $retryScheduled += $result->retryScheduled;
            $terminalFailed += $result->terminalFailed;
            $groupResults[] = $result;
        }

        return new ClickHouseExportResult(
            published: $published,
            retryScheduled: $retryScheduled,
            terminalFailed: $terminalFailed,
            skipped: $skipped,
            groups: $groupResults,
        );
    }

    /**
     * @throws ClickHouseExportException when the batch completes with one or more failed messages
     */
    public function exportOrFail(?int $limit = null): ClickHouseExportResult
    {
        $result = $this->export($limit);

        if ($result->hasFailures()) {
            throw ClickHouseExportException::fromResult($result);
        }

        return $result;
    }

    /**
     * @param array{table: non-empty-string, columns: non-empty-list<string>, rows: list<array<string, mixed>>, messages: list<OutboxMessage>} $group
     */
    private function exportGroup(array $group): ClickHouseExportGroupResult
    {
        $count = \count($group['messages']);

        try {
            $writer = $this->writerFactory->create($group['table'], $group['columns']);
            $writer->write($group['rows']);

            foreach ($group['messages'] as $message) {
                $this->storage->markPublished($message);
            }

            return new ClickHouseExportGroupResult(
                table: $group['table'],
                columns: $group['columns'],
                messageCount: $count,
                published: $count,
                retryScheduled: 0,
                terminalFailed: 0,
            );
        } catch (\Throwable $e) {
            $retry = 0;
            $terminal = 0;

            foreach ($group['messages'] as $message) {
                if ($this->persistFailure($message, $e) === FailureDecision::Terminal) {
                    $terminal++;
                } else {
                    $retry++;
                }
            }

            $this->logger->warning('ClickHouse outbox export group failed', [
                'table' => $group['table'],
                'messageCount' => $count,
                'error' => $e->getMessage(),
            ]);

            return new ClickHouseExportGroupResult(
                table: $group['table'],
                columns: $group['columns'],
                messageCount: $count,
                published: 0,
                retryScheduled: $retry,
                terminalFailed: $terminal,
            );
        }
    }

    private function persistFailure(OutboxMessage $message, \Throwable $e): FailureDecision
    {
        $decision = $this->failureDecider->decide($message, $e);

        if ($decision === FailureDecision::Terminal) {
            $this->storage->markFailed($message);
        } else {
            $this->storage->save($message->withStatus(OutboxStatus::Pending));
        }

        return $decision;
    }
}
