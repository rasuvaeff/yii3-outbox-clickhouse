<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseRouteException;

/**
 * Maps an outbox message to the table, columns and row it should be written as.
 *
 * @api
 */
interface ClickHouseMessageRouterInterface
{
    /**
     * @throws ClickHouseRouteException when the message cannot be routed (terminal)
     */
    public function route(OutboxMessage $message): ClickHouseMessageRoute;

    /**
     * Message types this router can handle, used to scope the exporter's poll
     * ({@see \Rasuvaeff\Yii3Outbox\StorageInterface::claim()}). Return an
     * empty list to handle every type (no scoping).
     *
     * Because `claim()` hands each message to exactly one caller, this list must
     * not overlap with the types any other consumer of the same outbox claims —
     * an overlapping message reaches only whichever worker claimed it first.
     *
     * @return list<string>
     */
    public function handledTypes(): array;
}
