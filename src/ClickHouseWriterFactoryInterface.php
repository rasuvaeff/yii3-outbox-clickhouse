<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;

/**
 * Builds a {@see ClickHouseWriterInterface} for a given table and column set.
 * Separating routing from writer construction keeps the exporter testable and
 * lets the writer (e.g. batch size) be tuned per table.
 *
 * @api
 */
interface ClickHouseWriterFactoryInterface
{
    /**
     * @param non-empty-string $table
     * @param non-empty-list<string> $columns
     */
    public function create(string $table, array $columns): ClickHouseWriterInterface;
}
