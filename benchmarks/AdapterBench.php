<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Benchmarks;

use DateTimeImmutable;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseMessageRoute;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Testo\Bench;

final class AdapterBench
{
    #[Bench(
        callables: [
            'six-columns' => [self::class, 'routeSixColumns'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function routeThreeColumns(): ClickHouseMessageRoute
    {
        $router = new MapClickHouseMessageRouter(
            routes: [
                'order.created' => [
                    'table' => 'ab_exposures',
                    'columns' => ['event_id', 'experiment', 'variant'],
                ],
            ],
        );

        $message = new OutboxMessage(
            id: 'msg-001',
            type: 'order.created',
            payload: '{"experiment":"checkout","variant":"treatment"}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable(),
        );

        return $router->route(message: $message);
    }

    public static function routeSixColumns(): ClickHouseMessageRoute
    {
        $router = new MapClickHouseMessageRouter(
            routes: [
                'order.created' => [
                    'table' => 'ab_exposures',
                    'columns' => ['event_id', 'experiment', 'variant', 'subject_id', 'is_forced', 'environment'],
                ],
            ],
        );

        $message = new OutboxMessage(
            id: 'msg-001',
            type: 'order.created',
            payload: '{"experiment":"checkout","variant":"treatment","subject_id":"u42","is_forced":0,"environment":"production"}',
            status: OutboxStatus::Pending,
            createdAt: new DateTimeImmutable(),
        );

        return $router->route(message: $message);
    }
}
