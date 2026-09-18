<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rasuvaeff\Yii3Outbox\BatchAcknowledgingStorageInterface;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryAwareStorageInterface;
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
 * `Pending` and retry later, until {@see RetryPolicy::shouldRetry()} runs out of
 * attempts, at which point they are marked `Failed` no matter what the decider
 * said. At-least-once delivery means retries may insert a
 * row twice; pair the target table with `ReplacingMergeTree` keyed on the routed
 * event id (see {@see MapClickHouseMessageRouter}).
 *
 * A successful group is acknowledged through
 * {@see BatchAcknowledgingStorageInterface::markPublishedBatch()} when the
 * storage offers it — one statement for the group instead of one
 * `markPublished()` per message. Whether the acknowledged rows are kept or
 * deleted is the storage's setting, not this exporter's.
 *
 * The storage failing is the one thing `export()` does not absorb: the
 * exception propagates, but not before every message the batch claimed and
 * had not yet resolved is released — saved back as `Pending`, or marked
 * `Failed` when it had no attempts left — the same contract
 * {@see \Rasuvaeff\Yii3Outbox\Processor} keeps. The release reaches for the
 * storage that just failed, so it is best-effort: its own failures are logged,
 * never thrown, and the caller always receives the exception that aborted the
 * batch.
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

    /**
     * @throws \Throwable whatever the storage threw while recording the outcome
     *                    of a message; the rest of the claimed batch has been
     *                    released by then
     */
    public function export(?int $limit = null): ClickHouseExportResult
    {
        $fetch = $limit ?? $this->fetchLimit;
        $now = $this->clock->now();

        $messages = $this->claimBatch($now, $fetch);

        // Every claimed message, until something in the storage records its
        // outcome. What is left here when the batch aborts is what the
        // release puts back.
        $unresolved = [];

        foreach ($messages as $message) {
            $unresolved[$message->getId()] = $message;
        }

        try {
            return $this->exportClaimed($messages, $now, $unresolved);
        } catch (\Throwable $e) {
            $this->release($unresolved);

            throw $e;
        }
    }

    /**
     * @param list<OutboxMessage> $messages
     * @param array<string, OutboxMessage> $unresolved
     */
    private function exportClaimed(array $messages, \DateTimeImmutable $now, array &$unresolved): ClickHouseExportResult
    {
        $published = 0;
        $retryScheduled = 0;
        $terminalFailed = 0;
        $skipped = 0;

        /** @var array<string, array{table: non-empty-string, columns: non-empty-list<string>, rows: list<array<string, mixed>>, messages: list<OutboxMessage>}> $groups */
        $groups = [];

        foreach ($messages as $message) {
            // A claimed message whose attempts are already spent has nowhere to
            // go but Failed. Saving it back as Pending — which is what a plain
            // isReadyForRetry() check does — makes it circle claim -> skip ->
            // save forever, invisible to any alert watching Failed.
            if (!$this->retryPolicy->shouldRetry($message)) {
                $this->logger->warning('ClickHouse outbox message exhausted its retries', [
                    'messageId' => $message->getId(),
                    'type' => $message->getType(),
                    'attempts' => $message->getAttempts(),
                ]);

                $this->storage->markFailed($message);
                unset($unresolved[$message->getId()]);
                $terminalFailed++;

                continue;
            }

            if (!$this->retryPolicy->isReadyForRetry($message, $now)) {
                $this->storage->save($message->withStatus(OutboxStatus::Pending));
                unset($unresolved[$message->getId()]);
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

                // The attempt is spent on the routing: a release records it.
                $unresolved[$message->getId()] = $message;

                if ($this->persistFailure($message, $e) === FailureDecision::Terminal) {
                    $terminalFailed++;
                } else {
                    $retryScheduled++;
                }

                unset($unresolved[$message->getId()]);

                continue;
            }

            $key = $route->groupKey();

            $groups[$key] ??= [
                'table' => $route->table,
                'columns' => $route->columns,
                'rows' => [],
                'messages' => [],
            ];

            $groups[$key]['rows'][] = $route->row;
            $groups[$key]['messages'][] = $message;
        }

        $groupResults = [];

        foreach ($groups as $group) {
            $result = $this->exportGroup($group, $unresolved);
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
     * Claims a batch, letting the storage apply the retry policy itself when it
     * can.
     *
     * The `isReadyForRetry()` check in `export()` stays either way. A plain
     * {@see StorageInterface} has no way to honour the predicate, and one that
     * does may still hand back more than asked — time moves between the query
     * and the loop. The pushdown removes work; it is not what makes the result
     * correct.
     *
     * @return list<OutboxMessage>
     */
    private function claimBatch(\DateTimeImmutable $now, int $fetch): array
    {
        if ($this->storage instanceof RetryAwareStorageInterface) {
            return $this->storage->claimReady(
                readyThreshold: $this->retryPolicy->readyThreshold($now),
                maxAttempts: $this->retryPolicy->getMaxAttempts(),
                types: $this->router->handledTypes(),
                limit: $fetch,
            );
        }

        return $this->storage->claim($this->router->handledTypes(), $fetch);
    }

    /**
     * @param array{table: non-empty-string, columns: non-empty-list<string>, rows: list<array<string, mixed>>, messages: list<OutboxMessage>} $group
     * @param array<string, OutboxMessage> $unresolved
     */
    private function exportGroup(array $group, array &$unresolved): ClickHouseExportGroupResult
    {
        $count = \count($group['messages']);

        // From here on the attempt is spent, whatever happens to the write:
        // a release records it.
        foreach ($group['messages'] as $message) {
            $unresolved[$message->getId()] = $message;
        }

        try {
            $writer = $this->writerFactory->create($group['table'], $group['columns']);
            $writer->write($group['rows']);
        } catch (\Throwable $e) {
            $retry = 0;
            $terminal = 0;

            foreach ($group['messages'] as $message) {
                if ($this->persistFailure($message, $e) === FailureDecision::Terminal) {
                    $terminal++;
                } else {
                    $retry++;
                }

                unset($unresolved[$message->getId()]);
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

        // The rows are in ClickHouse. A storage that fails to record that
        // is not a delivery failure the decider should rule on: the
        // exception propagates and the release puts the group back as
        // Pending — redelivered later, deduplicated by the event id.
        $this->acknowledge($group['messages']);

        foreach ($group['messages'] as $message) {
            unset($unresolved[$message->getId()]);
        }

        return new ClickHouseExportGroupResult(
            table: $group['table'],
            columns: $group['columns'],
            messageCount: $count,
            published: $count,
            retryScheduled: 0,
            terminalFailed: 0,
        );
    }

    /**
     * Puts back what the batch claimed but never resolved, once it is already
     * aborting. A message with attempts left is saved as `Pending` — with the
     * attempt it may have spent — and one without is marked `Failed`, exactly
     * what the loop would have done on reaching it.
     *
     * Each message is released independently and a failure to release one is
     * logged, not thrown: the storage is most likely what aborted the batch,
     * and the caller must still receive that original exception.
     *
     * @param array<string, OutboxMessage> $messages
     */
    private function release(array $messages): void
    {
        foreach ($messages as $message) {
            try {
                if ($this->retryPolicy->shouldRetry($message)) {
                    $this->storage->save($message->withStatus(OutboxStatus::Pending));

                    continue;
                }

                $this->logger->warning('ClickHouse outbox message exhausted its retries', [
                    'messageId' => $message->getId(),
                    'type' => $message->getType(),
                    'attempts' => $message->getAttempts(),
                ]);
                $this->storage->markFailed($message);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to release a claimed ClickHouse outbox message', [
                    'messageId' => $message->getId(),
                    'type' => $message->getType(),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The group result reports what reached the sink, so it is the group size
     * either way — never whatever the storage counted as touched.
     *
     * @param list<OutboxMessage> $messages
     */
    private function acknowledge(array $messages): void
    {
        if ($this->storage instanceof BatchAcknowledgingStorageInterface) {
            $this->storage->markPublishedBatch($messages);

            return;
        }

        foreach ($messages as $message) {
            $this->storage->markPublished($message);
        }
    }

    /**
     * Applies the decider's verdict, with the retry policy as the ceiling: a
     * retryable failure on a message that has no attempts left is terminal.
     * Without that the message would be saved as Pending with attempts already
     * at the maximum and never be retried nor terminated again.
     */
    private function persistFailure(OutboxMessage $message, \Throwable $e): FailureDecision
    {
        $decision = $this->failureDecider->decide($message, $e);

        if ($decision === FailureDecision::Retryable && !$this->retryPolicy->shouldRetry($message)) {
            $this->logger->warning('ClickHouse outbox message exhausted its retries', [
                'messageId' => $message->getId(),
                'type' => $message->getType(),
                'attempts' => $message->getAttempts(),
                'error' => $e->getMessage(),
            ]);

            $decision = FailureDecision::Terminal;
        }

        if ($decision === FailureDecision::Terminal) {
            $this->storage->markFailed($message);
        } else {
            $this->storage->save($message->withStatus(OutboxStatus::Pending));
        }

        return $decision;
    }
}
