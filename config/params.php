<?php

declare(strict_types=1);

use Rasuvaeff\Yii3OutboxClickHouse\Console\ExportClickHouseOutboxCommand;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'outbox:clickhouse:export' => ExportClickHouseOutboxCommand::class,
        ],
    ],
    'rasuvaeff/yii3-outbox-clickhouse' => [
        'fetchLimit' => 1000,
        'batchSize' => 1000,
        'eventIdColumn' => 'event_id',
        'idleSleepSeconds' => 5,
        'busySleepSeconds' => 1,
        'routes' => [
            // 'ab.exposure' => [
            //     'table' => 'ab_exposures',
            //     'columns' => ['event_id', 'experiment', 'variant', 'subject_id', 'is_forced', 'is_fallback', 'is_sticky', 'environment'],
            // ],
        ],
        'retry' => [
            'maxAttempts' => 5,
            'delaySeconds' => 30,
        ],
    ],
];
