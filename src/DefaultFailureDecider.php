<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseRouteException;

/**
 * Routing/decoding failures ({@see ClickHouseRouteException}) are terminal — bad
 * data does not heal on retry. ClickHouse write/transport failures
 * ({@see \Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException}) and any other
 * error are retryable; the
 * {@see \Rasuvaeff\Yii3Outbox\RetryPolicy} still caps the attempts, so a genuine
 * code bug eventually lands in `Failed` rather than retrying forever.
 *
 * @api
 */
final readonly class DefaultFailureDecider implements FailureDeciderInterface
{
    #[\Override]
    public function decide(OutboxMessage $message, \Throwable $e): FailureDecision
    {
        if ($e instanceof ClickHouseRouteException) {
            return FailureDecision::Terminal;
        }

        return FailureDecision::Retryable;
    }
}
