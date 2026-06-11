<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

/**
 * How a failed message should be handled.
 *
 * @api
 */
enum FailureDecision
{
    /** Keep the message `Pending` and retry later (transient: ClickHouse down, network). */
    case Retryable;

    /** Move the message to `Failed` immediately (bad payload, unknown type, invalid route). */
    case Terminal;
}
