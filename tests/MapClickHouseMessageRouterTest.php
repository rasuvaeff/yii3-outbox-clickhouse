<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseRouteException;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(MapClickHouseMessageRouter::class)]
final class MapClickHouseMessageRouterTest
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

    public function routesPayloadIntoRowInColumnOrder(): void
    {
        $router = new MapClickHouseMessageRouter(routes: self::ROUTES);
        $message = $this->message(id: 'm1', type: 'ab.exposure', payload: '{"experiment":"checkout","variant":"green","extra":"dropped"}');

        $route = $router->route($message);

        Assert::same($route->table, 'ab_exposures');
        Assert::same($route->columns, ['event_id', 'experiment', 'variant']);
        Assert::same($route->row, [
            'event_id' => 'm1',
            'experiment' => 'checkout',
            'variant' => 'green',
        ]);
    }

    public function injectsMessageIdIntoEventIdColumn(): void
    {
        $router = new MapClickHouseMessageRouter(routes: self::ROUTES);

        $route = $router->route($this->message(id: 'evt-42', type: 'ab.conversion', payload: '{"experiment":"x","goal":"purchase"}'));

        Assert::same($route->row['event_id'], 'evt-42');
    }

    public function eventIdColumnCanBeDisabled(): void
    {
        $router = new MapClickHouseMessageRouter(
            routes: ['t' => ['table' => 'tbl', 'columns' => ['event_id', 'a']]],
            eventIdColumn: null,
        );

        Expect::exception(ClickHouseRouteException::class);

        $router->route($this->message(id: 'm1', type: 't', payload: '{"a":1}'));
    }

    public function throwsOnUnknownType(): void
    {
        $router = new MapClickHouseMessageRouter(routes: self::ROUTES);

        Expect::exception(ClickHouseRouteException::class);

        $router->route($this->message(id: 'm1', type: 'order.created', payload: '{}'));
    }

    public function throwsOnMissingPayloadField(): void
    {
        $router = new MapClickHouseMessageRouter(routes: self::ROUTES);

        Expect::exception(ClickHouseRouteException::class);

        $router->route($this->message(id: 'm1', type: 'ab.exposure', payload: '{"experiment":"x"}'));
    }

    public function throwsOnNonScalarField(): void
    {
        $router = new MapClickHouseMessageRouter(routes: self::ROUTES);

        Expect::exception(ClickHouseRouteException::class);

        $router->route($this->message(id: 'm1', type: 'ab.exposure', payload: '{"experiment":{"nested":true},"variant":"green"}'));
    }

    public function allowsNullAndScalarFields(): void
    {
        $router = new MapClickHouseMessageRouter(
            routes: ['t' => ['table' => 'tbl', 'columns' => ['experiment', 'variant']]],
            eventIdColumn: null,
        );

        $route = $router->route($this->message(id: 'm1', type: 't', payload: '{"experiment":null,"variant":42}'));

        Assert::same($route->row, ['experiment' => null, 'variant' => 42]);
    }

    public function handledTypesReturnsRouteKeys(): void
    {
        $router = new MapClickHouseMessageRouter(routes: self::ROUTES);

        Assert::same($router->handledTypes(), ['ab.exposure', 'ab.conversion']);
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
