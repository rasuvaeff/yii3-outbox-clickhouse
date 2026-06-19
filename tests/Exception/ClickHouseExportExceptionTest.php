<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseExportResult;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseExportException;

#[CoversClass(ClickHouseExportException::class)]
final class ClickHouseExportExceptionTest extends TestCase
{
    #[Test]
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

        $this->assertSame($result, $exception->getResult());
        $this->assertSame(
            'ClickHouse export reported failures: 2 retry scheduled, 1 terminal failed',
            $exception->getMessage(),
        );
    }
}
