# rasuvaeff/yii3-outbox-clickhouse

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-outbox-clickhouse/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-outbox-clickhouse)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-outbox-clickhouse/downloads)](https://packagist.org/packages/rasuvaeff/yii3-outbox-clickhouse)
[![Build](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-outbox-clickhouse/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox-clickhouse)
[![License](https://poser.pugx.org/rasuvaeff/yii3-outbox-clickhouse/license)](https://packagist.org/packages/rasuvaeff/yii3-outbox-clickhouse)
[Русская версия](README.ru.md)

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
- `rasuvaeff/yii3-outbox` ^1.6, `rasuvaeff/clickhouse-toolkit` ^1.6
- `symfony/console` ^6.4 || ^7.0 — a hard dependency, installed for every
  consumer; the worker command is built on it
- A PSR-18 HTTP client + PSR-17 factories (e.g. `guzzlehttp/guzzle`)
- Optional: `ext-pcntl`, for the worker to finish its current batch on
  `SIGTERM` / `SIGINT` instead of being killed mid-flight

## Installation

```bash
composer require rasuvaeff/yii3-outbox-clickhouse
```

## Usage

### Exporter

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

$result = $exporter->export();   // one batch
```

### Running the worker

Run the loop with the bundled console command (registered for `yiisoft/yii-console`,
also works in plain Symfony Console):

```bash
./yii outbox:clickhouse:export                 # run forever
./yii outbox:clickhouse:export --once          # single batch (e.g. from cron)
./yii outbox:clickhouse:export --max-iterations=100
./yii outbox:clickhouse:export --once --fail-on-error   # cron that must notice
```

`--max-iterations` accepts non-negative integers up to `PHP_INT_MAX` only, `0`
meaning unlimited; anything else exits `Command::INVALID` instead of being
coerced. The check runs before `--once`, so a malformed value never slips
through as a successful single batch.

**Exit code.** The command exits `0` whatever the batches reported, so a
crontab keeps its behaviour. With `--fail-on-error` a run in which *any*
batch marked a message `Failed` exits `1` — a cron or systemd timer can then
tell a bad run apart. Retries scheduled during a ClickHouse outage do not
count; they are the normal course of things.

**Stopping.** `SIGTERM` and `SIGINT` end the loop *after the current batch*
when `ext-pcntl` is loaded: the batch in flight is written and acknowledged,
the pause between batches is cut short, and the command exits `0` with
`Stopped on signal after the current batch`. Without the extension the signal
kills the process as before, and the batch in flight waits for the storage's
stale-claim recovery. Kubernetes and systemd both send `SIGTERM` first.

Or drive the framework-agnostic `ClickHouseOutboxExportRunner` yourself:

```php
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExportRunner;

$runner = new ClickHouseOutboxExportRunner($exporter, idleSleepSeconds: 5, busySleepSeconds: 1);
$runner->run(
    static fn (int $iteration): bool => true,                 // stop condition
    static fn (int $seconds): mixed => sleep($seconds),       // sleeper, between batches only
    static fn (ClickHouseExportResult $batch): mixed => null, // optional: every batch as it completes
);
```

The sleeper runs *between* batches — never after the one the stop condition
declined to follow — so a loop bounded to one iteration exports once and
returns at once.

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

**The injected event id is the anchor this relies on.** Every retry path
inserts the same rows again — a ClickHouse outage, and since 1.6.0 also a
group whose rows reached ClickHouse but whose acknowledgement in the outbox
storage failed — and the only reason a second insert is harmless is that the
row carries a value the table's `ORDER BY` collapses on. Disabling the
injection (`'eventIdColumn' => null`) removes that anchor: the retry then
produces a duplicate nothing merges away. Disable it only when the payload
carries a stable id of its own, and make *that* column the `ORDER BY` of the
target table.

| Failure | Decision | Effect |
|---|---|---|
| Unknown type / bad payload / missing field (`ClickHouseRouteException`) | terminal | `markFailed` |
| ClickHouse down / transport error (`ClickHouseWriteException`) | retryable | `save`, stays `Pending`, retried per `RetryPolicy` |
| Retryable failure with no attempts left (`attempts >= maxAttempts`) | terminal | `markFailed` |

The retry policy is the ceiling, not the decider: a retryable verdict on a
message that has spent its last attempt becomes terminal, and a claimed message
whose attempts were already exhausted is marked `Failed` on sight. Without that
cap an outage longer than `maxAttempts x delaySeconds` would leave the whole
backlog `Pending` forever — re-claimed and skipped on every run, with nothing
for an alert on `Failed` to see.

`FailureDecision` and `FailureDeciderInterface` are the extension point behind
that table: implement the interface to classify your own exceptions and pass it
to the exporter (the container binds `DefaultFailureDecider` by default). The
attempt cap applies to whatever your decider returns.

**The storage failing is the one thing `export()` does not absorb.** A
`markFailed()`, `save()` or acknowledgement that throws — the OLTP database
is down, not ClickHouse — propagates out of `export()`, but only after every
message the batch claimed and had not yet resolved is released: saved back as
`Pending` (with the attempt it spent, if the write had already happened), or
marked `Failed` when it had no attempts left. That is the contract
`yii3-outbox`'s `Processor` keeps, and this exporter keeps it too, so a
storage incident does not leave half a batch `Processing` for a human to
find with raw SQL. A group whose rows reached ClickHouse but whose
acknowledgement failed goes back to `Pending` and is written again later —
at-least-once, deduplicated by the event id.

The release is best-effort: it needs the same storage that just failed. A
message it cannot release is logged (`Failed to release a claimed ClickHouse
outbox message`) and stays `Processing` until `releaseStaleClaims()` in
`yii3-outbox-db` moves it — keep that on a schedule. The exception the caller
receives is always the one that aborted the batch, never one raised while
reacting to it.

`export()` never throws on a ClickHouse outage. `ClickHouseExportResult` reports
`published` / `retryScheduled` / `terminalFailed` / `skipped` and per-group detail.
If a caller wants a catchable domain exception, `exportOrFail()` wraps a failed
batch in `Exception\ClickHouseExportException` and carries the result object.
`exportOrFail()` throws on **any** failed message, a scheduled retry included —
a transient ClickHouse outage does raise it. It does not throw on `skipped`
messages: those are still waiting out their backoff and were never attempted, so
they stay unpublished without counting as a failure. Check
`ClickHouseExportResult::hasTerminalFailures()` instead when only unrecoverable
messages should page someone.

### Messages in backoff are not claimed

When the injected storage implements `Rasuvaeff\Yii3Outbox\RetryAwareStorageInterface`
— `rasuvaeff/yii3-outbox-db` 2.2.0 does — the exporter claims through
`claimReady()`, and a message whose retry delay has not elapsed is never taken
from the table. Before, every `Pending` row of the routed types was claimed and
the not-yet-due ones were written straight back: two writes per backing-off
message per run, each occupying a slot in `fetchLimit` that a ready message
could have used, so export latency grew with the size of the retry queue.

A storage without the interface keeps working: the exporter falls back to
`claim()` and filters in PHP, exactly as before.

The consequence to know before alerting on it: `ClickHouseExportResult::$skipped`
reads `0` against a retry-aware storage, because the messages it counted are no
longer claimed. It was never queue depth — it was wasted effort, and there is
none left. Count `Pending` rows with a recent `last_attempt_at` if you want to
know how many are waiting.

### A group is acknowledged in one statement

A successful group used to be acknowledged with one `markPublished()` per
message — against `rasuvaeff/yii3-outbox-db` one upsert each, a thousand
statements in the OLTP database for a thousand-message group. When the storage
implements `Rasuvaeff\Yii3Outbox\BatchAcknowledgingStorageInterface` —
`yii3-outbox-db` 2.3.0 does — the exporter acknowledges the whole group with
one `markPublishedBatch()` call, one statement. A storage without the interface
is acknowledged one message at a time, exactly as before.

Whether an acknowledged row stays in the table as `Published` or is deleted is
the storage's setting, not the exporter's: `yii3-outbox-db` offers
`deletePublished: true` (params `delete_published`), under which the outbox
holds only `Pending`/`Processing`/`Failed` rows and needs no purge cron. What
was sent is then observed in ClickHouse — the `event_id` column is the outbox
id. At-least-once is unchanged either way: a crash between the insert and the
acknowledgement leaves the group `Processing`, the stale-claim release returns
it to `Pending`, and `ReplacingMergeTree` collapses the second insert.

`ClickHouseExportGroupResult::$published` is the number of messages written to
ClickHouse, whatever the acknowledgement touched.

Requires `rasuvaeff/yii3-outbox` ^1.6.

### Yii3 DI

`config/di.php` binds the exporter, router, decoder, failure decider and writer
factory. It does **not** bind `StorageInterface` — that is owned by the storage
backend (`yii3-outbox-db`) or the application.

**It does not bind `ClickHouseConfig` either, and you must.** The writer
factory is built from an autowired `ClickHouseClientFactory`, whose
`ClickHouseConfig` has a default for every constructor argument
(`127.0.0.1`, database `default`, user `default`, empty password). Without an
application binding the container assembles a client for localhost without a
word, and the worker retries against it forever instead of failing on a
configuration error. Either install `rasuvaeff/yii3-clickhouse-toolkit`, which
binds `ClickHouseConfig` from its params, or bind it yourself:

```php
// config/common/di.php
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;

return [
    ClickHouseConfig::class => static fn (): ClickHouseConfig => new ClickHouseConfig(
        host: 'clickhouse',
        database: 'analytics',
        username: 'exporter',
        password: getenv('CLICKHOUSE_PASSWORD') ?: '',
    ),
];
```
 Configure routes in params —
**the shipped default is an empty map and `MapClickHouseMessageRouter` rejects
it**. That is deliberate: an empty map handles no type, but an empty
`handledTypes()` tells `claim()` "every type", so the exporter would drain the
whole outbox — other consumers' messages included — and terminally fail every
one of them for having no route. Configure routes before running
`outbox:clickhouse:export`:

```php
// config/params.php
'rasuvaeff/yii3-outbox-clickhouse' => [
    'batchSize' => 1000,
    'fetchLimit' => 1000,
    'eventIdColumn' => 'event_id',   // null: take the column from the payload like any other —
                                     // only when that payload column is the table's dedup key
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
