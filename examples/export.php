<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Clock\ClockInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseWriterFactoryInterface;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;

// A fake writer factory that prints inserts instead of talking to ClickHouse,
// so this example runs without a server. In production use
// DefaultClickHouseWriterFactory(clientFactory: ...).
$writerFactory = new class implements ClickHouseWriterFactoryInterface {
    #[\Override]
    public function create(string $table, array $columns): ClickHouseWriterInterface
    {
        return new class($table) implements ClickHouseWriterInterface {
            public function __construct(private readonly string $table) {}

            #[\Override]
            public function write(iterable $rows): void
            {
                foreach ($rows as $row) {
                    echo "   INSERT INTO {$this->table} " . json_encode($row, JSON_THROW_ON_ERROR) . "\n";
                }
            }
        };
    }
};

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-11 12:00:00');
    }
};

$storage = new InMemoryStorage();
$outbox = new Outbox(storage: $storage, clock: $clock);

echo "1. Record events into the outbox:\n";
$outbox->record(type: 'ab.exposure', payload: '{"experiment":"checkout","variant":"green"}');
$outbox->record(type: 'ab.exposure', payload: '{"experiment":"checkout","variant":"control"}');
$outbox->record(type: 'ab.conversion', payload: '{"experiment":"checkout","goal":"purchase"}');
echo "   recorded 3\n";

$exporter = new ClickHouseOutboxExporter(
    storage: $storage,
    router: new MapClickHouseMessageRouter(routes: [
        'ab.exposure' => ['table' => 'ab_exposures', 'columns' => ['event_id', 'experiment', 'variant']],
        'ab.conversion' => ['table' => 'ab_conversions', 'columns' => ['event_id', 'experiment', 'goal']],
    ]),
    retryPolicy: new RetryPolicy(maxAttempts: 5, delaySeconds: 0),
    clock: $clock,
    writerFactory: $writerFactory,
);

echo "2. Export (grouped batched inserts, event_id from the message id):\n";
$result = $exporter->export();

echo "3. Result:\n";
echo "   published={$result->published} groups={$result->groupCount()} retry={$result->retryScheduled} terminal={$result->terminalFailed} skipped={$result->skipped}\n";
echo '   pending after export: ' . count($storage->findPending()) . "\n";
