# Changelog

## 1.6.0 — 2026-09-18

### Added

- `outbox:clickhouse:export --fail-on-error`: exit `1` when any batch of the
  run marked a message `Failed`, so a cron or systemd timer can tell a bad
  run apart. The default exit code stays `0`; scheduled retries never count
  (#29).
- Graceful stop: with `ext-pcntl` loaded, `SIGTERM` / `SIGINT` end the worker
  loop after the current batch is written and acknowledged, and cut the pause
  between batches short. `Console\GracefulStop` is the request the command
  checks; it can be injected and requested programmatically. `ext-pcntl` is
  suggested, not required (#29).
- `ClickHouseOutboxExportRunner::run()` takes an optional third callable that
  receives every batch's `ClickHouseExportResult` as it completes (#29).

### Changed

- A storage failure mid-batch no longer leaves the rest of the claimed batch
  in `Processing`. `export()` releases every message it claimed but had not
  resolved — back to `Pending`, with the attempt it spent if the write had
  happened, or `Failed` when out of attempts — before the exception
  propagates, the way `Processor` in `yii3-outbox` does. A group whose rows
  reached ClickHouse but whose acknowledgement threw is now released as
  `Pending` and the exception propagates, instead of the failure being ruled
  on by the decider as if ClickHouse had failed. Release failures are logged
  (`Failed to release a claimed ClickHouse outbox message`), never thrown
  (#26).
- The runner sleeps between batches, never after the one the stop condition
  declined to follow: `--max-iterations=1` no longer sleeps `idleSleepSeconds`
  for nothing before exiting (#28).

### Fixed

- `config/di.php`: `'eventIdColumn' => null` in params now disables the id
  injection as the router's constructor allows; `??` used to turn it back
  into the `event_id` default (#28).
- README: two consecutive `### Worker` headings; `clickhouse-toolkit`
  constraint is `^1.6`, not `^1.1`; `symfony/console` is a hard dependency;
  the DI section states that `ClickHouseConfig` must be bound by the
  application — its defaults silently point the writer at `127.0.0.1` (#27).
- `composer.json` declares `extra.branch-alias` (`dev-master` → `1.x-dev`) so
  the family's config-merge harness can resolve the package from a path
  repository.

## 1.5.0 — 2026-09-16

### Changed

- A successful group is acknowledged with one `markPublishedBatch()` call when
  the storage implements `BatchAcknowledgingStorageInterface`
  (`rasuvaeff/yii3-outbox` 1.6.0; `rasuvaeff/yii3-outbox-db` 2.3.0 does) —
  one statement instead of one `markPublished()` per message. A storage without
  the interface is acknowledged one message at a time, as before. Keep-vs-delete
  of acknowledged rows is the storage's setting
  ([#24](https://github.com/rasuvaeff/yii3-outbox-clickhouse/issues/24)).
- Requires `rasuvaeff/yii3-outbox` `^1.6`.

## 1.4.0 — 2026-08-20

### Changed

- `ClickHouseOutboxExporter` claims through `claimReady()` when the injected
  storage implements `RetryAwareStorageInterface` (`rasuvaeff/yii3-outbox`
  1.5.0; implemented by `rasuvaeff/yii3-outbox-db` 2.2.0), so a message whose
  retry delay has not elapsed is never taken from the table
  ([#21](https://github.com/rasuvaeff/yii3-outbox-clickhouse/issues/21)).

  Every `Pending` row of the routed types used to be claimed and the not-yet-due
  ones written straight back — two writes per backing-off message per run, plus
  the row locks, and each one occupied a slot in `fetchLimit` that a ready
  message could have used, so export latency grew with the size of the retry
  queue. A storage without the interface keeps working unchanged: the exporter
  falls back to `claim()` and filters in PHP.

- `ClickHouseExportResult::$skipped` reads `0` against a retry-aware storage —
  the messages it counted are no longer claimed. It never was queue depth; it
  was wasted effort, and there is none left. Count `Pending` rows with a recent
  `last_attempt_at` to see how many are waiting.

- Requires `rasuvaeff/yii3-outbox` ^1.5 (was ^1.0): `RetryAwareStorageInterface`
  does not exist below it.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.3.0 — 2026-08-20

### Fixed

- Terminate a message whose retries are exhausted instead of leaving it
  `Pending` forever. `persistFailure()` applied the failure decider's verdict
  without consulting `RetryPolicy`, and the not-ready-for-retry branch of
  `export()` saved the message back as `Pending`, so once `attempts` reached
  `maxAttempts` the message circled claim → skip → save on every run:
  `terminalFailed` stayed 0, no alert on `Failed` could fire, and a ClickHouse
  outage longer than `maxAttempts x delaySeconds` (2.5 minutes with the shipped
  defaults) stranded the whole backlog invisibly
  ([#16](https://github.com/rasuvaeff/yii3-outbox-clickhouse/issues/16)).
- Reject an empty route map in `MapClickHouseMessageRouter`. An empty map makes
  `handledTypes()` return `[]`, which `claim()` reads as "every type": installing
  the package and running `outbox:clickhouse:export` without configuring routes
  claimed the entire outbox — messages belonging to other consumers included —
  and terminally failed each one for having no route
  ([#17](https://github.com/rasuvaeff/yii3-outbox-clickhouse/issues/17)).
- `--max-iterations` now rejects anything that is not a non-negative integer no
  greater than `PHP_INT_MAX`, and does so before `--once` is honoured.
  `max(0, (int) $value)` silently turned `--max-iterations=-5` into "run
  forever"; a digit string past `PHP_INT_MAX` was clamped by the same cast, and
  `--once` returned before the option was looked at at all
  ([#18](https://github.com/rasuvaeff/yii3-outbox-clickhouse/issues/18)).

### Added

- `ClickHouseExportResult::hasTerminalFailures()` — `hasFailures()`, which
  `exportOrFail()` throws on, also counts scheduled retries, so a transient
  outage raises it, while `skipped` messages waiting out their backoff do not
  make it true. Both are now documented for what they are
  ([#18](https://github.com/rasuvaeff/yii3-outbox-clickhouse/issues/18)).
- `ConfigWiringTest` covers `config/di.php` and `config/params.php`, neither of
  which is reached by psalm (src-only) or cs.
- A property test over the export lifecycle: for any batch of messages, any
  attempt counts, due times, routability and writer outcome, every message ends
  published, retryable-and-Pending or `Failed` — never `Processing`, never
  `Pending` with no attempts left — and foreign types are never touched.

### Changed

- Document `FailureDecision` / `FailureDeciderInterface` in both READMEs and
  `llms.txt`; correct the `DefaultFailureDecider` docblock, which claimed a cap
  it did not implement.
- Development tooling: `rasuvaeff/rector-named-literals` in the rector set, a
  narrow change filter for the mutation job, and a cached property regression
  corpus.
- Raise the Infection gate from `minMsi` 90 to 95 (the suite scores 97.2%).
- Raise the `yiisoft/test-support` dev floor to `^3.1` — `ConfigWiringTest` uses
  `StaticClock`, which 3.0 does not ship (the `prefer-lowest` CI job caught it).

## 1.2.0 — 2026-07-26

- Raise the `rasuvaeff/clickhouse-toolkit` floor from `^1.1` to `^1.6`.
  `Identifier` validation anchored its whitelists with `$` until v1.5.1, and in
  PCRE `$` also matches immediately before a trailing newline — so an identifier
  ending in `\n` passed validation. `^1.1` still resolved to a fixed version for
  a fresh install, but permitted the vulnerable v1.1.0–v1.4.0 for anyone with an
  older lock file, and the CI `prefer-lowest` job installed exactly those.
  `yii3-ab-testing-clickhouse` and `yii3-clickhouse-toolkit` were already on
  `^1.6`; this aligns the family.

  Minor rather than patch: raising a dependency floor can affect resolution for
  an application pinned to an older `clickhouse-toolkit`.

## 1.1.2 — 2026-07-26

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.
  Previously filed under a second `## 1.1.1 — 2026-06-30` heading; v1.1.1 was
  tagged on 2026-06-27 and never contained this change.
- Docs: the exporter polls via `claim()`, not `findPending()`. `llms.txt` and the
  `ClickHouseMessageRouterInterface::handledTypes()` PHPDoc still named the
  non-atomic method, and the claim "never competes with other consumers" was
  stated without its actual precondition — disjoint `handledTypes()` across
  consumers, since `claim()` hands a message to exactly one caller.

## 1.1.1 — 2026-06-27

- Migrate test suite from PHPUnit to Testo. Internal change, no public API impact.

## 1.1.0 — 2026-06-19

- Added opt-in strict export flow: `ClickHouseOutboxExporter::exportOrFail()` throws `Exception\ClickHouseExportException` when a completed batch reports retryable or terminal failures, while `export()` keeps the existing result-based semantics.

## 1.0.0 — 2026-06-12

- `ClickHouseOutboxExporter` — reads pending outbox messages, routes and groups them by `(table, columns)`, and writes one batched insert per group via `rasuvaeff/clickhouse-toolkit`. Retry/terminal semantics follow `RetryPolicy` and `FailureDeciderInterface`; ClickHouse being down never throws out of `export()`.
- `ClickHouseMessageRouterInterface` + `MapClickHouseMessageRouter` — config-driven `type => [table, columns]` routing. A configured `event_id` column is filled from `OutboxMessage::getId()`, so a `ReplacingMergeTree` keyed on it makes at-least-once retries idempotent.
- `ClickHousePayloadDecoderInterface` + `JsonPayloadDecoder`.
- `FailureDecision` + `FailureDeciderInterface` + `DefaultFailureDecider` (route errors terminal, transport errors retryable).
- `ClickHouseWriterFactoryInterface` + `DefaultClickHouseWriterFactory` (builds the client through the toolkit's `ClickHouseClientFactory`; no direct ClickHouse-client dependency).
- `ClickHouseExportResult` / `ClickHouseExportGroupResult` — per-run and per-group counters.
- `ClickHouseOutboxExportRunner` — framework-agnostic worker loop around the exporter (injected stop condition + sleeper; shorter sleep when busy, longer when idle).
- `Console\ExportClickHouseOutboxCommand` — Symfony Console / `yiisoft/yii-console` command `outbox:clickhouse:export` (`--once`, `--max-iterations`).
- Yii3 config-plugin: binds the exporter, runner, router, decoder, failure decider and writer factory from `config/di.php`; registers the console command and routes/batch/retry/sleep in `config/params.php`. Does not bind `StorageInterface` (owned by the outbox storage backend).

