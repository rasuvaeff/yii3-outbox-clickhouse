# AGENTS.md — yii3-outbox-clickhouse

Guidance for AI agents working on this package. Read before changing code.

## What this is

A **domain-agnostic** batched exporter from `rasuvaeff/yii3-outbox` to ClickHouse.
A worker calls `ClickHouseOutboxExporter::export()`, which reads pending messages,
routes each to a `(table, columns, row)`, groups them, and writes one batched
insert per group via `rasuvaeff/clickhouse-toolkit`. Namespace:
`Rasuvaeff\Yii3OutboxClickHouse`.

Public API: `ClickHouseOutboxExporter`, `ClickHouseMessageRouterInterface` +
`MapClickHouseMessageRouter`, `ClickHouseMessageRoute`,
`ClickHousePayloadDecoderInterface` + `JsonPayloadDecoder`, `FailureDecision` +
`FailureDeciderInterface` + `DefaultFailureDecider`,
`ClickHouseWriterFactoryInterface` + `DefaultClickHouseWriterFactory`,
`ClickHouseExportResult` + `ClickHouseExportGroupResult`,
`Exception\ClickHouseRouteException` + `Exception\ClickHouseExportException`,
`ClickHouseOutboxExportRunner` (worker loop) + `Console\ExportClickHouseOutboxCommand` (Symfony Console command
`outbox:clickhouse:export`, needs `symfony/console`).

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Stay domain-agnostic.** No `Assignment`, `ExposureTracker`, `ab.*` or other
   domain types in `src/`. Concrete message types and route maps belong in
   `examples/`, tests, or the consumer package — never the exporter core.
4. **No direct ClickHouse-client dependency.** Build the client through the
   toolkit's `ClickHouseClientFactory` (see `DefaultClickHouseWriterFactory`); do
   not `require` or reference `simpod/clickhouse-client` in `src/`.
5. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- **The exporter claims through `claimReady()` when it can.** If the injected
  storage implements `Rasuvaeff\Yii3Outbox\RetryAwareStorageInterface`, a
  message still waiting out its backoff is never claimed — no write-back, no
  slot consumed in `fetchLimit`. The `isReadyForRetry()` check in the loop is
  still required and must not be removed as "dead": a plain `StorageInterface`
  cannot honour the predicate, and even a retry-aware one may return a message
  that has drifted, since time moves between the query and the loop.
- **`ClickHouseExportResult::$skipped` is `0` against a retry-aware storage.**
  It counts messages the run claimed and then discarded, and the pushdown means
  there are none. A test asserting a non-zero `skipped` is asserting the
  fallback path and must use `Tests\Double\PlainStorage` — `InMemoryStorage`
  implements `RetryAwareStorageInterface` as of `rasuvaeff/yii3-outbox` 1.5.0,
  so using it directly silently exercises the other branch.
- **At-least-once + ClickHouse = duplicates.** A retry after a partial failure
  re-inserts rows. The router fills the configured `event_id` column from
  `OutboxMessage::getId()`; the target table must be `ReplacingMergeTree` ordered
  by that column so duplicates collapse on merge.
- One insert per `(table, ordered columns)` group; events of different shape
  never share a batch (`ClickHouseMessageRoute::groupKey()`).
- A group write either publishes every message or applies one
  `FailureDecision` to all of them — no per-row acknowledgement.
- Route/decode errors are **terminal** (`markFailed`); transport errors are
  **retryable** (`save`, stays `Pending`). `export()` never throws on a ClickHouse
  outage. `exportOrFail()` is an opt-in strict wrapper that converts failed
  batches into `Exception\ClickHouseExportException`.
- The exporter scopes the poll to `router->handledTypes()`, passed as the
  `$types` argument of the claim, so it never competes with a generic
  `Processor` or another exporter for foreign messages. It claims — never
  `findPending()`, which locks and marks nothing and would hand the same rows
  to two workers.
- The exporter does not bind/own `StorageInterface` — that is the storage
  backend's (`yii3-outbox-db`) or app's responsibility.
- Integration tests need a real ClickHouse; skipped unless `CLICKHOUSE_HOST` set.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build` (monorepo-root mount). For a real ClickHouse run, start
  a server and set `CLICKHOUSE_HOST`. Paste the output.
