<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseExportGroupResult;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseExportResult;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ClickHouseExportResult::class)]
#[Covers(ClickHouseExportGroupResult::class)]
final class ClickHouseExportResultTest
{
    public function aggregatesCounters(): void
    {
        $group = new ClickHouseExportGroupResult(
            table: 'ab_exposures',
            columns: ['event_id', 'experiment'],
            messageCount: 5,
            published: 3,
            retryScheduled: 2,
            terminalFailed: 0,
        );

        $result = new ClickHouseExportResult(
            published: 3,
            retryScheduled: 2,
            terminalFailed: 1,
            skipped: 4,
            groups: [$group],
        );

        Assert::same($result->totalHandled(), 6);
        Assert::same($result->groupCount(), 1);
        Assert::true($result->hasFailures());
        Assert::same($result->groups[0]->table, 'ab_exposures');
    }

    public function reportsNoFailuresWhenClean(): void
    {
        $result = new ClickHouseExportResult(
            published: 10,
            retryScheduled: 0,
            terminalFailed: 0,
            skipped: 0,
            groups: [],
        );

        Assert::false($result->hasFailures());
        Assert::same($result->totalHandled(), 10);
        Assert::same($result->groupCount(), 0);
    }

    public function hasFailuresWhenOnlyRetryScheduled(): void
    {
        $result = new ClickHouseExportResult(published: 0, retryScheduled: 1, terminalFailed: 0, skipped: 0, groups: []);

        Assert::true($result->hasFailures());
    }

    public function hasFailuresWhenOnlyTerminalFailed(): void
    {
        $result = new ClickHouseExportResult(published: 0, retryScheduled: 0, terminalFailed: 1, skipped: 0, groups: []);

        Assert::true($result->hasFailures());
    }
}
