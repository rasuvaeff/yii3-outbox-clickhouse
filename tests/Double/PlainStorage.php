<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Double;

use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\StorageInterface;

/**
 * An {@see InMemoryStorage} exposed as a bare {@see StorageInterface}.
 *
 * `InMemoryStorage` implements `RetryAwareStorageInterface` as of
 * `rasuvaeff/yii3-outbox` 1.5.0, so the exporter claims through `claimReady()`
 * whenever it is used directly. This double is how a test reaches the fallback
 * path — a backend that cannot apply the readiness predicate itself, where the
 * exporter still claims everything and discards in PHP.
 */
final class PlainStorage implements StorageInterface
{
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
        $this->inner->markPublished($message);
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
