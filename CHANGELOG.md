# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0 — 2026-06-12

- `DefaultAbTestingOutboxMessageFactory` now stamps `event_at` (event time, UTC `Y-m-d H:i:s`) into every payload from an injected `Psr\Clock\ClockInterface` (default `SystemClock`) — the moment the event was tracked, not when the worker later exports it. Adds `psr/clock` as a runtime dependency.
- `AbTestingClickHouseRoutes::map()` adds the `event_at` column (after `event_id`); both are transport-meta columns excluded from the source-of-truth contract.

## 1.0.0 — 2026-06-12

- `OutboxExposureTracker` / `OutboxConversionTracker` — `ExposureTracker` / `ConversionTracker` implementations that record each event as a durable outbox message via `Outbox::record()`. Deliberately not `FlushableTracker` (the event is persisted immediately).
- `AbTestingOutboxEventType` — stable message types `ab.exposure` / `ab.conversion`.
- `AbTestingOutboxMessageFactoryInterface` + `DefaultAbTestingOutboxMessageFactory` — JSON payloads with `0|1` flags and an always-present `environment`; deterministic aggregate ids.
- `AbTestingClickHouseRoutes::map()` — ready-made route map for `yii3-outbox-clickhouse` (analytics columns mirror `yii3-ab-testing-clickhouse`, with a leading `event_id` for `ReplacingMergeTree` dedup; a contract test guards the match).
- Yii3 config-plugin: binds `ExposureTracker` and `ConversionTracker` from `config/di.php`.
