<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseExportResult;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseExportException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ClickHouseExportException::class)]
final class ClickHouseExportExceptionTest
{
    public function exposesFailedExportResult(): void
    {
        $result = new ClickHouseExportResult(
            published: 0,
            retryScheduled: 2,
            terminalFailed: 1,
            skipped: 0,
            groups: [],
        );

        $exception = ClickHouseExportException::fromResult($result);

        Assert::same($exception->getResult(), $result);
        Assert::same(
            $exception->getMessage(),
            'ClickHouse export reported failures: 2 retry scheduled, 1 terminal failed',
        );
    }
}
