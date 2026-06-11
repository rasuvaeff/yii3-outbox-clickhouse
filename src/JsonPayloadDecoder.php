<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseRouteException;

/**
 * Decodes a JSON-object payload. A non-JSON or non-object payload is a terminal
 * routing failure — retrying cannot fix malformed data.
 *
 * @api
 */
final readonly class JsonPayloadDecoder implements ClickHousePayloadDecoderInterface
{
    #[\Override]
    public function decode(OutboxMessage $message): array
    {
        try {
            $decoded = json_decode($message->getPayload(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ClickHouseRouteException(
                sprintf('Invalid JSON payload for message "%s": %s', $message->getId(), $e->getMessage()),
                previous: $e,
            );
        }

        if (!\is_array($decoded)) {
            throw new ClickHouseRouteException(
                sprintf('Payload for message "%s" must be a JSON object, got %s', $message->getId(), get_debug_type($decoded)),
            );
        }

        foreach (array_keys($decoded) as $key) {
            if (!\is_string($key)) {
                throw new ClickHouseRouteException(
                    sprintf('Payload for message "%s" must be a JSON object with string keys', $message->getId()),
                );
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
