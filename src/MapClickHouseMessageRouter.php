<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseRouteException;

/**
 * Config-driven router: a `type => [table, columns]` map. Each row is projected
 * from the decoded payload, in column order.
 *
 * One column may carry the durable event id instead of a payload field: when a
 * route lists the configured `$eventIdColumn` (default `event_id`), it is filled
 * from {@see OutboxMessage::getId()}, not from the payload. Pointing a
 * `ReplacingMergeTree` ORDER BY at that column makes at-least-once retries
 * idempotent — duplicate inserts collapse on merge.
 *
 * @api
 */
final readonly class MapClickHouseMessageRouter implements ClickHouseMessageRouterInterface
{
    /**
     * @param array<string, array{table: non-empty-string, columns: non-empty-list<string>}> $routes
     * @param string|null $eventIdColumn column filled from the message id, or null to disable id injection
     */
    public function __construct(
        private array $routes,
        private ClickHousePayloadDecoderInterface $decoder = new JsonPayloadDecoder(),
        private ?string $eventIdColumn = 'event_id',
    ) {}

    #[\Override]
    public function route(OutboxMessage $message): ClickHouseMessageRoute
    {
        $type = $message->getType();

        if (!isset($this->routes[$type])) {
            throw new ClickHouseRouteException(sprintf('No ClickHouse route configured for message type "%s"', $type));
        }

        $table = $this->routes[$type]['table'];
        $columns = $this->routes[$type]['columns'];
        $payload = $this->decoder->decode($message);

        /** @var array<string, mixed> $row */
        $row = [];

        foreach ($columns as $column) {
            if ($column === $this->eventIdColumn) {
                $row[$column] = $message->getId();

                continue;
            }

            if (!array_key_exists($column, $payload)) {
                throw new ClickHouseRouteException(
                    sprintf('Missing field "%s" in payload of message "%s" (type "%s")', $column, $message->getId(), $type),
                );
            }

            $value = $payload[$column];

            if ($value !== null && !\is_scalar($value)) {
                throw new ClickHouseRouteException(
                    sprintf('Field "%s" of message "%s" must be a scalar or null, got %s', $column, $message->getId(), get_debug_type($value)),
                );
            }

            $row[$column] = $value;
        }

        return new ClickHouseMessageRoute(table: $table, columns: $columns, row: $row);
    }

    #[\Override]
    public function handledTypes(): array
    {
        return array_keys($this->routes);
    }
}
