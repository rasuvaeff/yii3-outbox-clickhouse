<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Integration;

use Psr\Clock\ClockInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3Outbox\OutboxStatus;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultClickHouseWriterFactory;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[CoversNothing]
final class ClickHouseIntegrationTest
{
    private const string TABLE = 'ob_ch_export_test';

    private const array ROUTES = [
        'test.event' => ['table' => self::TABLE, 'columns' => ['event_id', 'experiment']],
    ];

    private ?ClickHouseClientFactory $clientFactory = null;

    #[BeforeTest]
    public function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST');
        if ($host === false || $host === '') {
            return;
        }

        $this->clientFactory = new ClickHouseClientFactory(new ClickHouseConfig(
            host: $host,
            port: (int) self::env('CLICKHOUSE_PORT', '8123'),
            database: self::env('CLICKHOUSE_DB', 'default'),
            username: self::env('CLICKHOUSE_USER', 'default'),
            password: self::env('CLICKHOUSE_PASSWORD', ''),
        ));

        $client = $this->clientFactory->create();
        $client->executeQuery('DROP TABLE IF EXISTS ' . self::TABLE);
        $client->executeQuery(
            'CREATE TABLE ' . self::TABLE . ' (event_id String, experiment String, ts DateTime DEFAULT now())'
            . ' ENGINE = ReplacingMergeTree ORDER BY event_id',
        );
    }

    public function exportsBatchedMessagesToClickHouse(): void
    {
        if ($this->clientFactory === null) {
            Assert::true(true);

            return;
        }

        $storage = new InMemoryStorage();
        $clock = $this->fixedClock();
        $outbox = new Outbox(storage: $storage, clock: $clock);
        $outbox->record(type: 'test.event', payload: '{"experiment":"x"}');
        $outbox->record(type: 'test.event', payload: '{"experiment":"y"}');

        $exporter = new ClickHouseOutboxExporter(
            storage: $storage,
            router: new MapClickHouseMessageRouter(routes: self::ROUTES),
            retryPolicy: new RetryPolicy(maxAttempts: 5, delaySeconds: 0),
            clock: $clock,
            writerFactory: new DefaultClickHouseWriterFactory(clientFactory: $this->clientFactory),
        );

        $result = $exporter->export();

        Assert::same($result->published, 2);
        Assert::same($this->countRows(), 2);

        foreach ($storage->findPending() as $_) {
            Assert::fail('No message should remain pending after a successful export');
        }
    }

    public function duplicateEventIdsCollapseOnReplacingMergeTree(): void
    {
        if ($this->clientFactory === null) {
            Assert::true(true);

            return;
        }

        $writerFactory = new DefaultClickHouseWriterFactory(clientFactory: $this->clientFactory);
        $writer = $writerFactory->create(self::TABLE, ['event_id', 'experiment']);

        $writer->write([['event_id' => 'dup-1', 'experiment' => 'x']]);
        $writer->write([['event_id' => 'dup-1', 'experiment' => 'x']]);

        $this->clientFactory->create()->executeQuery('OPTIMIZE TABLE ' . self::TABLE . ' FINAL');

        Assert::same($this->countRows(), 1);
    }

    public function exportMarksMessagesPublished(): void
    {
        if ($this->clientFactory === null) {
            Assert::true(true);

            return;
        }

        $storage = new InMemoryStorage();
        $clock = $this->fixedClock();
        $message = (new Outbox(storage: $storage, clock: $clock))->record(type: 'test.event', payload: '{"experiment":"x"}');

        (new ClickHouseOutboxExporter(
            storage: $storage,
            router: new MapClickHouseMessageRouter(routes: self::ROUTES),
            retryPolicy: new RetryPolicy(maxAttempts: 5, delaySeconds: 0),
            clock: $clock,
            writerFactory: new DefaultClickHouseWriterFactory(clientFactory: $this->clientFactory),
        ))->export();

        $stored = $storage->getById($message->getId());
        Assert::notNull($stored);
        Assert::same($stored->getStatus(), OutboxStatus::Published);
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-06-11 12:00:00');
            }
        };
    }

    private function countRows(): int
    {
        $reader = new ClickHouseDataReader(
            client: $this->clientFactory->create(),
            table: self::TABLE,
            queryBuilder: ClickHouseQueryBuilder::create(allowedFields: []),
            mapper: static fn(array $row): array => $row,
            columns: [],
        );

        return $reader->count();
    }
}
