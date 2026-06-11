<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

use Rasuvaeff\Yii3Outbox\OutboxMessage;

/**
 * Classifies an export failure as retryable or terminal.
 *
 * @api
 */
interface FailureDeciderInterface
{
    public function decide(OutboxMessage $message, \Throwable $e): FailureDecision;
}
