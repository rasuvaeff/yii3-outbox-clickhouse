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
- Yii3 config-plugin: binds the exporter, router, decoder, failure decider and writer factory from `config/di.php`; routes/batch/retry in `config/params.php`. Does not bind `StorageInterface` (owned by the outbox storage backend).
