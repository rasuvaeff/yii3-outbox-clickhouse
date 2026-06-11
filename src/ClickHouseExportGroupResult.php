<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

/**
 * Outcome of one insert group (a single (table, columns) batch).
 *
 * @api
 */
final readonly class ClickHouseExportGroupResult
{
    /**
     * @param non-empty-string $table
     * @param non-empty-list<string> $columns
     */
    public function __construct(
        public string $table,
        public array $columns,
        public int $messageCount,
        public int $published,
        public int $retryScheduled,
        public int $terminalFailed,
    ) {}
}
