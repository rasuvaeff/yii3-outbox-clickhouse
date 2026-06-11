<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;

/**
 * Builds a {@see ClickHouseBatchWriter} per (table, columns). The PSR-based
 * ClickHouse client is created through the toolkit's {@see ClickHouseClientFactory},
 * so this package never depends on a concrete ClickHouse client directly.
 *
 * @api
 */
final readonly class DefaultClickHouseWriterFactory implements ClickHouseWriterFactoryInterface
{
    public function __construct(
        private ClickHouseClientFactory $clientFactory,
        private int $batchSize = 1000,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException(sprintf('Batch size must be at least 1, got %d', $batchSize));
        }
    }

    #[\Override]
    public function create(string $table, array $columns): ClickHouseWriterInterface
    {
        return new ClickHouseBatchWriter(
            client: $this->clientFactory->create(),
            table: $table,
            columns: $columns,
            batchSize: $this->batchSize,
        );
    }
}
