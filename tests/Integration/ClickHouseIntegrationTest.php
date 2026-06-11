<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

/**
 * End-to-end test against a real ClickHouse server. Skipped unless
 * CLICKHOUSE_HOST is set. Exports outbox messages as batched inserts and checks
 * that at-least-once retries collapse on a ReplacingMergeTree keyed by event id.
 */
#[CoversNothing]
final class ClickHouseIntegrationTest extends TestCase
{
    private const string TABLE = 'ob_ch_export_test';

    private const array ROUTES = [
        'test.event' => ['table' => self::TABLE, 'columns' => ['event_id', 'experiment']],
    ];

    private ClickHouseClientFactory $clientFactory;

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    #[\Override]
    protected function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('CLICKHOUSE_HOST is not set; skipping integration tests.');
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

    #[Test]
    public function exportsBatchedMessagesToClickHouse(): void
    {
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

        $this->assertSame(2, $result->published);
        $this->assertSame(2, $this->countRows());

        foreach ($storage->findPending() as $_) {
            $this->fail('No message should remain pending after a successful export');
        }
    }

    #[Test]
    public function duplicateEventIdsCollapseOnReplacingMergeTree(): void
    {
        $writerFactory = new DefaultClickHouseWriterFactory(clientFactory: $this->clientFactory);
        $writer = $writerFactory->create(self::TABLE, ['event_id', 'experiment']);

        // Simulate an at-least-once retry: the same event id inserted twice.
        $writer->write([['event_id' => 'dup-1', 'experiment' => 'x']]);
        $writer->write([['event_id' => 'dup-1', 'experiment' => 'x']]);

        $this->clientFactory->create()->executeQuery('OPTIMIZE TABLE ' . self::TABLE . ' FINAL');

        $this->assertSame(1, $this->countRows());
    }

    #[Test]
    public function exportMarksMessagesPublished(): void
    {
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
        $this->assertNotNull($stored);
        $this->assertSame(OutboxStatus::Published, $stored->getStatus());
    }

    private function fixedClock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-06-11 12:00:00'));

        return $clock;
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
