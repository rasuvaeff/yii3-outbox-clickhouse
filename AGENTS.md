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
`Exception\ClickHouseRouteException`, `ClickHouseOutboxExportRunner` (worker loop)
+ `Console\ExportClickHouseOutboxCommand` (Symfony Console command
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

No PHP/Composer on the host — run in Docker via the `composer:2` image. Core
`yii3-outbox` is consumed via a path repository while unpublished, so mount the
**monorepo root**:

```bash
# install (inject path repo with a version override, then revert + drop the lock)
docker run --rm -v "$REPO_ROOT":/repo -w /repo/yii3-outbox-clickhouse composer:2 sh -c '
  git config --global --add safe.directory "*";
  composer config repositories.core "{\"type\":\"path\",\"url\":\"../yii3-outbox\",\"options\":{\"versions\":{\"rasuvaeff/yii3-outbox\":\"1.0.0\"}}}";
  composer update -q;
  git checkout composer.json;
  rm -f composer.lock'

# build
docker run --rm -v "$REPO_ROOT":/repo -w /repo/yii3-outbox-clickhouse composer:2 composer build
```

`composer.json` keeps `rasuvaeff/yii3-outbox: ^1.0` (Packagist), no committed
`repositories` block. GitHub CI is red until core is on Packagist — expected.
`composer.lock` is gitignored (library).

## Invariants & gotchas

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
  outage.
- The exporter scopes the poll to `router->handledTypes()` via
  `StorageInterface::findPending($types, $limit)`, so it never competes with a
  generic `Processor` or another exporter for foreign messages.
- The exporter does not bind/own `StorageInterface` — that is the storage
  backend's (`yii3-outbox-db`) or app's responsibility.
- Integration tests need a real ClickHouse; skipped unless `CLICKHOUSE_HOST` set.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build` (monorepo-root mount). For a real ClickHouse run, start
  a server and set `CLICKHOUSE_HOST`. Paste the output.
