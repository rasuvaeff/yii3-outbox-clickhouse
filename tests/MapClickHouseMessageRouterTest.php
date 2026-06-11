<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseRouteException;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;

#[CoversClass(MapClickHouseMessageRouter::class)]
final class MapClickHouseMessageRouterTest extends TestCase
{
    private const array ROUTES = [
        'ab.exposure' => [
            'table' => 'ab_exposures',
            'columns' => ['event_id', 'experiment', 'variant'],
        ],
        'ab.conversion' => [
            'table' => 'ab_conversions',
            'columns' => ['event_id', 'experiment', 'goal'],
        ],
    ];

    #[Test]
    public function routesPayloadIntoRowInColumnOrder(): void
    {
        $router = new MapClickHouseMessageRouter(routes: self::ROUTES);
        $message = $this->message(id: 'm1', type: 'ab.exposure', payload: '{"experiment":"checkout","variant":"green","extra":"dropped"}');

        $route = $router->route($message);

        $this->assertSame('ab_exposures', $route->table);
        $this->assertSame(['event_id', 'experiment', 'variant'], $route->columns);
        $this->assertSame([
            'event_id' => 'm1',
            'experiment' => 'checkout',
            'variant' => 'green',
        ], $route->row);
    }

    #[Test]
    public function injectsMessageIdIntoEventIdColumn(): void
    {
        $router = new MapClickHouseMessageRouter(routes: self::ROUTES);

        $route = $router->route($this->message(id: 'evt-42', type: 'ab.conversion', payload: '{"experiment":"x","goal":"purchase"}'));

        $this->assertSame('evt-42', $route->row['event_id']);
    }

    #[Test]
    public function eventIdColumnCanBeDisabled(): void
    {
        $router = new MapClickHouseMessageRouter(
            routes: ['t' => ['table' => 'tbl', 'columns' => ['event_id', 'a']]],
            eventIdColumn: null,
        );

        $this->expectException(ClickHouseRouteException::class);
        $this->expectExceptionMessage('Missing field "event_id"');

        $router->route($this->message(id: 'm1', type: 't', payload: '{"a":1}'));
    }

    #[Test]
    public function throwsOnUnknownType(): void
    {
        $router = new MapClickHouseMessageRouter(routes: self::ROUTES);

        $this->expectException(ClickHouseRouteException::class);
        $this->expectExceptionMessage('No ClickHouse route configured for message type "order.created"');

        $router->route($this->message(id: 'm1', type: 'order.created', payload: '{}'));
    }

    #[Test]
    public function throwsOnMissingPayloadField(): void
    {
        $router = new MapClickHouseMessageRouter(routes: self::ROUTES);

        $this->expectException(ClickHouseRouteException::class);
        $this->expectExceptionMessage('Missing field "variant"');

        $router->route($this->message(id: 'm1', type: 'ab.exposure', payload: '{"experiment":"x"}'));
    }

    #[Test]
    public function throwsOnNonScalarField(): void
    {
        $router = new MapClickHouseMessageRouter(routes: self::ROUTES);

        $this->expectException(ClickHouseRouteException::class);
        $this->expectExceptionMessage('must be a scalar or null');

        $router->route($this->message(id: 'm1', type: 'ab.exposure', payload: '{"experiment":{"nested":true},"variant":"green"}'));
    }

    #[Test]
    public function allowsNullAndScalarFields(): void
    {
        $router = new MapClickHouseMessageRouter(
            routes: ['t' => ['table' => 'tbl', 'columns' => ['experiment', 'variant']]],
            eventIdColumn: null,
        );

        $route = $router->route($this->message(id: 'm1', type: 't', payload: '{"experiment":null,"variant":42}'));

        $this->assertSame(['experiment' => null, 'variant' => 42], $route->row);
    }

    #[Test]
    public function handledTypesReturnsRouteKeys(): void
    {
        $router = new MapClickHouseMessageRouter(routes: self::ROUTES);

        $this->assertSame(['ab.exposure', 'ab.conversion'], $router->handledTypes());
    }

    private function message(string $id, string $type, string $payload): OutboxMessage
    {
        return new OutboxMessage(
            id: $id,
            type: $type,
            payload: $payload,
            status: OutboxStatus::Pending,
            createdAt: new \DateTimeImmutable('2026-06-11 12:00:00'),
        );
    }
}
