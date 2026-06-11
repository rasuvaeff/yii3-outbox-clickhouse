<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseMessageRouterInterface;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHousePayloadDecoderInterface;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseWriterFactoryInterface;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultClickHouseWriterFactory;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultFailureDecider;
use Rasuvaeff\Yii3OutboxClickHouse\FailureDeciderInterface;
use Rasuvaeff\Yii3OutboxClickHouse\JsonPayloadDecoder;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;

/** @var array $params */

return [
    ClickHousePayloadDecoderInterface::class => JsonPayloadDecoder::class,
    FailureDeciderInterface::class => DefaultFailureDecider::class,

    ClickHouseWriterFactoryInterface::class => static function (
        ClickHouseClientFactory $clientFactory,
    ) use ($params): DefaultClickHouseWriterFactory {
        $config = $params['rasuvaeff/yii3-outbox-clickhouse'] ?? [];

        return new DefaultClickHouseWriterFactory(
            clientFactory: $clientFactory,
            batchSize: (int) ($config['batchSize'] ?? 1000),
        );
    },

    ClickHouseMessageRouterInterface::class => static function (
        ClickHousePayloadDecoderInterface $decoder,
    ) use ($params): MapClickHouseMessageRouter {
        $config = $params['rasuvaeff/yii3-outbox-clickhouse'] ?? [];

        return new MapClickHouseMessageRouter(
            routes: $config['routes'] ?? [],
            decoder: $decoder,
            eventIdColumn: $config['eventIdColumn'] ?? 'event_id',
        );
    },

    ClickHouseOutboxExporter::class => static function (
        StorageInterface $storage,
        ClickHouseMessageRouterInterface $router,
        ClockInterface $clock,
        ClickHouseWriterFactoryInterface $writerFactory,
        FailureDeciderInterface $failureDecider,
    ) use ($params): ClickHouseOutboxExporter {
        $config = $params['rasuvaeff/yii3-outbox-clickhouse'] ?? [];
        $retry = $config['retry'] ?? [];

        return new ClickHouseOutboxExporter(
            storage: $storage,
            router: $router,
            retryPolicy: new RetryPolicy(
                maxAttempts: (int) ($retry['maxAttempts'] ?? 5),
                delaySeconds: (int) ($retry['delaySeconds'] ?? 30),
            ),
            clock: $clock,
            writerFactory: $writerFactory,
            failureDecider: $failureDecider,
            fetchLimit: (int) ($config['fetchLimit'] ?? 1000),
        );
    },
];
