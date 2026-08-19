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

    /**
     * True when the batch left any message unpublished — both the retryable
     * ones, which the next run picks up, and the terminal ones. This is what
     * {@see ClickHouseOutboxExporter::exportOrFail()} throws on, so a
     * ClickHouse outage that the exporter has already scheduled for retry does
     * raise it; use {@see self::hasTerminalFailures()} when only unrecoverable
     * messages should be reported.
     */
    public function hasFailures(): bool
    {
        return $this->retryScheduled > 0 || $this->terminalFailed > 0;
    }

    /**
     * True when the batch gave up on at least one message: bad data, or the
     * retry policy running out of attempts. These messages are `Failed` and no
     * further run will touch them.
     */
    public function hasTerminalFailures(): bool
    {
        return $this->terminalFailed > 0;
    }
}
