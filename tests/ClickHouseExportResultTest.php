<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseExportGroupResult;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseExportResult;

#[CoversClass(ClickHouseExportResult::class)]
#[CoversClass(ClickHouseExportGroupResult::class)]
final class ClickHouseExportResultTest extends TestCase
{
    #[Test]
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

        $this->assertSame(6, $result->totalHandled());
        $this->assertSame(1, $result->groupCount());
        $this->assertTrue($result->hasFailures());
        $this->assertSame('ab_exposures', $result->groups[0]->table);
    }

    #[Test]
    public function reportsNoFailuresWhenClean(): void
    {
        $result = new ClickHouseExportResult(
            published: 10,
            retryScheduled: 0,
            terminalFailed: 0,
            skipped: 0,
            groups: [],
        );

        $this->assertFalse($result->hasFailures());
        $this->assertSame(10, $result->totalHandled());
        $this->assertSame(0, $result->groupCount());
    }

    #[Test]
    public function hasFailuresWhenOnlyRetryScheduled(): void
    {
        $result = new ClickHouseExportResult(published: 0, retryScheduled: 1, terminalFailed: 0, skipped: 0, groups: []);

        $this->assertTrue($result->hasFailures());
    }

    #[Test]
    public function hasFailuresWhenOnlyTerminalFailed(): void
    {
        $result = new ClickHouseExportResult(published: 0, retryScheduled: 0, terminalFailed: 1, skipped: 0, groups: []);

        $this->assertTrue($result->hasFailures());
    }
}
