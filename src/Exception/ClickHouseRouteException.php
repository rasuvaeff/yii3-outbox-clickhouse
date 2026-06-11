<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Exception;

/**
 * Thrown when a message cannot be turned into a ClickHouse row: unknown type,
 * invalid payload, or a missing required field. Treated as a terminal failure by
 * {@see \Rasuvaeff\Yii3OutboxClickHouse\DefaultFailureDecider} — retrying will not
 * fix it.
 *
 * @api
 */
final class ClickHouseRouteException extends \RuntimeException {}
