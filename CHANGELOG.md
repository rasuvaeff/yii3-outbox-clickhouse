# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 — unreleased

- `ClickHouseOutboxExporter` — reads pending outbox messages, routes and groups them by `(table, columns)`, and writes one batched insert per group via `rasuvaeff/clickhouse-toolkit`. Retry/terminal semantics follow `RetryPolicy` and `FailureDeciderInterface`; ClickHouse being down never throws out of `export()`.
- `ClickHouseMessageRouterInterface` + `MapClickHouseMessageRouter` — config-driven `type => [table, columns]` routing. A configured `event_id` column is filled from `OutboxMessage::getId()`, so a `ReplacingMergeTree` keyed on it makes at-least-once retries idempotent.
- `ClickHousePayloadDecoderInterface` + `JsonPayloadDecoder`.
- `FailureDecision` + `FailureDeciderInterface` + `DefaultFailureDecider` (route errors terminal, transport errors retryable).
- `ClickHouseWriterFactoryInterface` + `DefaultClickHouseWriterFactory` (builds the client through the toolkit's `ClickHouseClientFactory`; no direct ClickHouse-client dependency).
- `ClickHouseExportResult` / `ClickHouseExportGroupResult` — per-run and per-group counters.
- `ClickHouseOutboxExportRunner` — framework-agnostic worker loop around the exporter (injected stop condition + sleeper; shorter sleep when busy, longer when idle).
- `Console\ExportClickHouseOutboxCommand` — Symfony Console / `yiisoft/yii-console` command `outbox:clickhouse:export` (`--once`, `--max-iterations`).
- Yii3 config-plugin: binds the exporter, runner, router, decoder, failure decider and writer factory from `config/di.php`; registers the console command and routes/batch/retry/sleep in `config/params.php`. Does not bind `StorageInterface` (owned by the outbox storage backend).
