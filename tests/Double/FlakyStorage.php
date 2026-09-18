<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Double;

use DateTimeImmutable;
use Rasuvaeff\Yii3Outbox\BatchAcknowledgingStorageInterface;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\RetryAwareStorageInterface;

/**
 * An {@see InMemoryStorage} whose writes fail on demand: after $after
 * successful calls of $failing, the next $failures calls throw
 * {@see StorageDown}, and later ones go through. With `PHP_INT_MAX` failures
 * the storage never recovers, which is how a test reaches the release's own
 * error path.
 *
 * Reads (`claim*`, `getById`, `findPending`) never fail: the batch under test
 * must have been claimed for its release to matter.
 */
final class FlakyStorage implements RetryAwareStorageInterface, BatchAcknowledgingStorageInterface
{
    public const string SAVE = 'save';
    public const string MARK_FAILED = 'markFailed';
    public const string MARK_PUBLISHED = 'markPublished';
    public const string MARK_PUBLISHED_BATCH = 'markPublishedBatch';

    /** @var list<string> every write, by method name, in call order — thrown or not */
    public array $writes = [];

    public function __construct(
        public readonly InMemoryStorage $inner,
        private readonly string $failing,
        private int $remaining = 1,
        private int $after = 0,
    ) {}

    private function write(string $method): void
    {
        $this->writes[] = $method;

        if ($method !== $this->failing) {
            return;
        }

        if ($this->after > 0) {
            $this->after--;

            return;
        }

        if ($this->remaining > 0) {
            $this->remaining--;

            throw new StorageDown(sprintf('%s() failed', $method));
        }
    }

    #[\Override]
    public function save(OutboxMessage $message): void
    {
        $this->write(self::SAVE);
        $this->inner->save($message);
    }

    #[\Override]
    public function markFailed(OutboxMessage $message): void
    {
        $this->write(self::MARK_FAILED);
        $this->inner->markFailed($message);
    }

    #[\Override]
    public function markPublished(OutboxMessage $message): void
    {
        $this->write(self::MARK_PUBLISHED);
        $this->inner->markPublished($message);
    }

    #[\Override]
    public function markPublishedBatch(array $messages): void
    {
        $this->write(self::MARK_PUBLISHED_BATCH);
        $this->inner->markPublishedBatch($messages);
    }

    #[\Override]
    public function findPending(array $types = [], int $limit = 1000): array
    {
        return $this->inner->findPending($types, $limit);
    }

    #[\Override]
    public function claim(array $types = [], int $limit = 1000): array
    {
        return $this->inner->claim($types, $limit);
    }

    #[\Override]
    public function claimReady(DateTimeImmutable $readyThreshold, int $maxAttempts, array $types = [], int $limit = 1000): array
    {
        return $this->inner->claimReady($readyThreshold, $maxAttempts, $types, $limit);
    }

    #[\Override]
    public function getById(string $id): ?OutboxMessage
    {
        return $this->inner->getById($id);
    }
}
