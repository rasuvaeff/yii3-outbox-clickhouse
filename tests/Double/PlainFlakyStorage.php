<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Double;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\StorageInterface;

/**
 * A {@see FlakyStorage} exposed as a bare {@see StorageInterface}, so the
 * exporter claims through `claim()` and acknowledges one message at a time.
 */
final readonly class PlainFlakyStorage implements StorageInterface
{
    public function __construct(private FlakyStorage $flaky) {}

    #[\Override]
    public function save(OutboxMessage $message): void
    {
        $this->flaky->save($message);
    }

    #[\Override]
    public function findPending(array $types = [], int $limit = 1000): array
    {
        return $this->flaky->findPending($types, $limit);
    }

    #[\Override]
    public function claim(array $types = [], int $limit = 1000): array
    {
        return $this->flaky->claim($types, $limit);
    }

    #[\Override]
    public function markPublished(OutboxMessage $message): void
    {
        $this->flaky->markPublished($message);
    }

    #[\Override]
    public function markFailed(OutboxMessage $message): void
    {
        $this->flaky->markFailed($message);
    }

    #[\Override]
    public function getById(string $id): ?OutboxMessage
    {
        return $this->flaky->getById($id);
    }
}
