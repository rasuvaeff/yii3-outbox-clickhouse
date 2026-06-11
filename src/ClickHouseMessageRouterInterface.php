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
     * Message types this router can handle, used to scope the pending poll
     * ({@see \Rasuvaeff\Yii3Outbox\StorageInterface::findPending()}). Return an
     * empty list to handle every type (no scoping).
     *
     * @return list<string>
     */
    public function handledTypes(): array;
}
