<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Exception;

use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseExportResult;

/**
 * Thrown by the opt-in strict export flow when a batch completes with one or
 * more failed messages.
 *
 * @api
 */
final class ClickHouseExportException extends \RuntimeException
{
    private function __construct(
        private readonly ClickHouseExportResult $result,
    ) {
        parent::__construct(sprintf(
            'ClickHouse export reported failures: %d retry scheduled, %d terminal failed',
            $result->retryScheduled,
            $result->terminalFailed,
        ));
    }

    public static function fromResult(ClickHouseExportResult $result): self
    {
        return new self($result);
    }

    public function getResult(): ClickHouseExportResult
    {
        return $this->result;
    }
}
