# rasuvaeff/yii3-outbox-clickhouse

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-outbox-clickhouse/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-outbox-clickhouse)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-outbox-clickhouse/downloads)](https://packagist.org/packages/rasuvaeff/yii3-outbox-clickhouse)
[![Build](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-outbox-clickhouse/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox-clickhouse)
[![License](https://poser.pugx.org/rasuvaeff/yii3-outbox-clickhouse/license)](https://packagist.org/packages/rasuvaeff/yii3-outbox-clickhouse)

Batched ClickHouse exporter for [`rasuvaeff/yii3-outbox`](https://github.com/rasuvaeff/yii3-outbox).
A worker drains the outbox and writes large batched inserts to ClickHouse, so the
request path stays fast and durable and ClickHouse outages are absorbed by the
outbox retry machinery. **Domain-agnostic** — reuse it for A/B analytics, audit
logs, product events, anything append-only.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference you can use.

## Why not write to ClickHouse from the request?

A per-request flush produces one small insert per request — ClickHouse hates many
small inserts, and a ClickHouse outage breaks the request. This package instead
batches **across** requests from a durable outbox and retries on failure. For a
request-scoped direct sink, see `rasuvaeff/yii3-ab-testing-clickhouse`.

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-outbox` ^1.0, `rasuvaeff/clickhouse-toolkit` ^1.1
- A PSR-18 HTTP client + PSR-17 factories (e.g. `guzzlehttp/guzzle`)

## Installation

```bash
composer require rasuvaeff/yii3-outbox-clickhouse
```

## Usage

### Worker

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultClickHouseWriterFactory;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3Outbox\RetryPolicy;

$router = new MapClickHouseMessageRouter(routes: [
    'ab.exposure' => [
        'table' => 'ab_exposures',
        'columns' => ['event_id', 'experiment', 'variant', 'subject_id'],
    ],
]);

$exporter = new ClickHouseOutboxExporter(
    storage: $storage,            // a yii3-outbox StorageInterface (e.g. yii3-outbox-db)
    router: $router,
    retryPolicy: new RetryPolicy(maxAttempts: 5, delaySeconds: 30),
    clock: $clock,
    writerFactory: new DefaultClickHouseWriterFactory(
        clientFactory: new ClickHouseClientFactory(new ClickHouseConfig(host: 'clickhouse')),
        batchSize: 1000,
    ),
);

while (true) {
    $result = $exporter->export();
    sleep($result->totalHandled() === 0 ? 5 : 1);
}
```

### Routing

`MapClickHouseMessageRouter` maps `type => [table, columns]`. Each row is built
from the decoded JSON payload in column order; a configured `event_id` column
(default name `event_id`) is filled from the message id instead of the payload.

### Idempotency (at-least-once)

Outbox delivery is at-least-once: a retry after a partial failure can insert a row
twice. Make the target table a `ReplacingMergeTree` ordered by the event id, so
duplicates collapse on merge:

```sql
CREATE TABLE ab_exposures (
    event_id   String,
    experiment String,
    variant    String,
    subject_id String,
    ts         DateTime DEFAULT now()
) ENGINE = ReplacingMergeTree ORDER BY event_id;
```

### Failure semantics

| Failure | Decision | Effect |
|---|---|---|
| Unknown type / bad payload / missing field (`ClickHouseRouteException`) | terminal | `markFailed` |
| ClickHouse down / transport error (`ClickHouseWriteException`) | retryable | `save`, stays `Pending`, retried per `RetryPolicy` |

`export()` never throws on a ClickHouse outage. `ClickHouseExportResult` reports
`published` / `retryScheduled` / `terminalFailed` / `skipped` and per-group detail.

### Yii3 DI

`config/di.php` binds the exporter, router, decoder, failure decider and writer
factory. It does **not** bind `StorageInterface` — that is owned by the storage
backend (`yii3-outbox-db`) or the application. Configure routes in params:

```php
// config/params.php
'rasuvaeff/yii3-outbox-clickhouse' => [
    'batchSize' => 1000,
    'fetchLimit' => 1000,
    'eventIdColumn' => 'event_id',
    'routes' => ['ab.exposure' => ['table' => 'ab_exposures', 'columns' => ['event_id', 'experiment']]],
    'retry' => ['maxAttempts' => 5, 'delaySeconds' => 30],
],
```

## Security

- Table/column identifiers and values go through `clickhouse-toolkit`
  (parameterized inserts, identifier validation).
- Payloads may contain PII; retention is the table/schema designer's
  responsibility.
- ClickHouse credentials live in `ClickHouseConfig`, never in payloads.

## Examples

See [`examples/`](examples/).

## Development

```bash
make build
```

Core `yii3-outbox` is consumed via a path repository while unpublished — see
[AGENTS.md](AGENTS.md) for the monorepo-root Docker invocation.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
