<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

use InvalidArgumentException;

/**
 * Transport-level description of where and how one outbox message is written:
 * the target table, the insert columns (order matters), and the projected row.
 *
 * @api
 */
final readonly class ClickHouseMessageRoute
{
    /**
     * @var non-empty-string
     */
    public string $table;

    /**
     * @var non-empty-list<string>
     */
    public array $columns;

    /**
     * @var array<string, mixed>
     */
    public array $row;

    /**
     * @param list<string> $columns
     * @param array<string, mixed> $row
     */
    public function __construct(string $table, array $columns, array $row)
    {
        if ($table === '') {
            throw new InvalidArgumentException('Route table must not be empty');
        }

        if ($columns === []) {
            throw new InvalidArgumentException('Route columns must not be empty');
        }

        $this->table = $table;
        $this->columns = $columns;
        $this->row = $row;
    }

    /**
     * Stable grouping key: one insert per (table, ordered columns). Events with a
     * different shape must not be mixed into the same batch.
     */
    public function groupKey(): string
    {
        return $this->table . "\0" . implode("\0", $this->columns);
    }
}
