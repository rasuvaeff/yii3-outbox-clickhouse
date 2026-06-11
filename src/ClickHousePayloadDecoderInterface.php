<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseRouteException;

/**
 * Decodes an {@see OutboxMessage} payload into an associative array of fields a
 * router can project into a row.
 *
 * @api
 */
interface ClickHousePayloadDecoderInterface
{
    /**
     * @return array<string, mixed>
     *
     * @throws ClickHouseRouteException when the payload cannot be decoded (terminal)
     */
    public function decode(OutboxMessage $message): array;
}
