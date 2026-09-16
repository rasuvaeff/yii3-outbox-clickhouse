<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Double;

use Rasuvaeff\Yii3Outbox\BatchAcknowledgingStorageInterface;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;

/**
 * An {@see InMemoryStorage} that records how it is acknowledged: every
 * `markPublishedBatch()` call as the list of ids it carried, every
 * `markPublished()` call by id. The exporter must use the former for a
 * successful group and never the latter.
 */
final class BatchAcknowledgingStorage implements BatchAcknowledgingStorageInterface
{
    /** @var list<list<string>> */
    public array $batches = [];

    /** @var list<string> */
    public array $acknowledged = [];

    public function __construct(private readonly InMemoryStorage $inner = new InMemoryStorage()) {}

    #[\Override]
    public function save(OutboxMessage $message): void
    {
        $this->inner->save($message);
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
    public function markPublished(OutboxMessage $message): void
    {
        $this->acknowledged[] = $message->getId();
        $this->inner->markPublished($message);
    }

    #[\Override]
    public function markPublishedBatch(array $messages): void
    {
        $this->batches[] = array_map(static fn(OutboxMessage $message): string => $message->getId(), $messages);
        $this->inner->markPublishedBatch($messages);
    }

    #[\Override]
    public function markFailed(OutboxMessage $message): void
    {
        $this->inner->markFailed($message);
    }

    #[\Override]
    public function getById(string $id): ?OutboxMessage
    {
        return $this->inner->getById($id);
    }
}
