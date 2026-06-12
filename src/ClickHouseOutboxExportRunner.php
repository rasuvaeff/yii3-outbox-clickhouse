<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

use InvalidArgumentException;

/**
 * Framework-agnostic worker loop around {@see ClickHouseOutboxExporter}: it
 * exports a batch, sleeps (shorter when work was done, longer when idle), and
 * repeats. The sleeping and stop condition are injected, so the loop is fully
 * testable and any console/daemon can drive it.
 *
 * @api
 */
final readonly class ClickHouseOutboxExportRunner
{
    public function __construct(
        private ClickHouseOutboxExporter $exporter,
        private int $idleSleepSeconds = 5,
        private int $busySleepSeconds = 1,
    ) {
        if ($idleSleepSeconds < 0 || $busySleepSeconds < 0) {
            throw new InvalidArgumentException('Sleep seconds must be non-negative');
        }
    }

    public function runOnce(): ClickHouseExportResult
    {
        return $this->exporter->export();
    }

    /**
     * Runs export batches until $shouldContinue returns false.
     *
     * @param callable(int): bool $shouldContinue receives the 1-based iteration number; return false to stop
     * @param callable(int): void $sleeper receives the seconds to sleep after each batch
     *
     * @return ClickHouseExportResult the result of the last batch (empty if none ran)
     */
    public function run(callable $shouldContinue, callable $sleeper): ClickHouseExportResult
    {
        $result = new ClickHouseExportResult(published: 0, retryScheduled: 0, terminalFailed: 0, skipped: 0, groups: []);
        $iteration = 0;

        while ($shouldContinue(++$iteration)) {
            $result = $this->exporter->export();
            $sleeper($result->totalHandled() === 0 ? $this->idleSleepSeconds : $this->busySleepSeconds);
        }

        return $result;
    }
}
