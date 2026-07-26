# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

