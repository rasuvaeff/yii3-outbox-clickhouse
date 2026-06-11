<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

/**
 * Detailed outcome of one {@see ClickHouseOutboxExporter::export()} run.
 *
 * @api
 */
final readonly class ClickHouseExportResult
{
    /**
     * @param list<ClickHouseExportGroupResult> $groups
     */
    public function __construct(
        public int $published,
        public int $retryScheduled,
        public int $terminalFailed,
        public int $skipped,
        public array $groups,
    ) {}

    public function totalHandled(): int
    {
        return $this->published + $this->retryScheduled + $this->terminalFailed;
    }

    public function groupCount(): int
    {
        return \count($this->groups);
    }

    public function hasFailures(): bool
    {
        return $this->retryScheduled > 0 || $this->terminalFailed > 0;
    }
}
