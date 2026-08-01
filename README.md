# rasuvaeff/yii3-ab-testing-outbox

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-ab-testing-outbox/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-outbox)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-ab-testing-outbox/downloads)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-outbox)
[![Build](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-ab-testing-outbox/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-ab-testing-outbox)
[![License](https://poser.pugx.org/rasuvaeff/yii3-ab-testing-outbox/license)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-outbox)
[Русская версия](README.ru.md)

Records [`rasuvaeff/yii3-ab-testing`](https://github.com/rasuvaeff/yii3-ab-testing)
exposure and conversion events into [`rasuvaeff/yii3-outbox`](https://github.com/rasuvaeff/yii3-outbox)
as durable messages. The request path stays fast and survives analytics outages;
a worker exports the outbox asynchronously (e.g. with `yii3-outbox-clickhouse`).

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference you can use.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer
> plugin also get this package's agent skill synced into `.agents/skills/`
> automatically on install.

## Direct sink vs durable pipeline

| | Direct | Durable (this package) |
|---|---|---|
| Package | `yii3-ab-testing-clickhouse` | `yii3-ab-testing-outbox` + `yii3-outbox(-db)` + `yii3-outbox-clickhouse` |
| Batching | per request | large, cross-request |
| Survives ClickHouse outage | no | yes |
| Setup | minimal | worker + outbox storage |

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^1.2
- `rasuvaeff/yii3-outbox` ^1.0
- `psr/clock` ^1.0

## Installation

```bash
composer require rasuvaeff/yii3-ab-testing-outbox
```

For the complete durable ClickHouse path, also install the DB storage and
exporter used by the tested pipeline:

```bash
composer require rasuvaeff/yii3-outbox-db rasuvaeff/yii3-outbox-clickhouse
```

## Usage

```php
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3Outbox\Outbox;

$outbox = new Outbox(storage: $storage, clock: $clock);   // storage from yii3-outbox-db
$exposureTracker = new OutboxExposureTracker($outbox);
$conversionTracker = new OutboxConversionTracker($outbox);

$assignment = $abTesting->assign(experiment: 'checkout', subjectId: $userId);
// The facade mints the event and calls the tracker; its identity travels
// with it, so a retry of the same domain event stays one row.
$exposure = $ab->trackExposure($assignment);
// later, on the goal:
$ab->trackConversion($assignment, goal: 'purchase', exposure: $exposure);
```

### Payload

`ab.exposure` / `ab.conversion` messages carry the canonical schema v2 row,
produced by the core's `CanonicalEventSerializer` — not assembled here. That is
deliberate: in v1 each delivery path built its own array, they dropped different
fields, and nothing noticed until the data was already wrong.

```json
{"v":2,"event_id":"0198f2c1-4d3a-7c9e-8b21-6f4a2d9e0c17","occurred_at":"2026-08-01 10:00:00.123","experiment":"checkout","variant":"green","subject_id":"user-1","decision_reason":"assigned","assignment_source":"computed","experiment_revision":"db:7","environment":"production","dimensions":"{\"country\":\"RU\"}"}
```

Conversions add `goal` and `exposure_event_id`.

| Field | Note |
|---|---|
| `v` | schema generation; transport meta, never written to ClickHouse |
| `event_id` | minted by the core, the deduplication key of the whole pipeline |
| `occurred_at` | event time, not export time — the partition key derives from it |
| `decision_reason` | `assigned`, `forced`, `fallback_disabled`, `fallback_targeting_mismatch`; only `assigned` counts as participation |
| `assignment_source` | `computed` or `store` (served from a sticky store) |
| `dimensions` | a JSON **string**, not a nested object: the exporter rejects non-scalar payload fields |

Analytics dimensions are filtered by the core's `AllowListAnalyticsContextPolicy`,
configured on the `AbTesting` facade. It moved there in 2.0 so that every
delivery path applies one allow-list; configured here it filtered the durable
path only.

Payload consumers must branch on `v` and accept every version advertised during
a rollout — see [UPGRADE.md](UPGRADE.md) for draining v1 messages queued before
the upgrade.

`AbTestingOutboxEventType` fixes the two message types (`ab.exposure`,
`ab.conversion`); `AbTestingOutboxPayload` is what the factory hands to
`Outbox::record()` — the type, the JSON payload and the aggregate id.

### Identity and retries

The default `PseudonymousAggregateIdStrategy` emits stable HMAC-SHA-256 ids such
as `exposure:<digest>` and never copies raw `subject_id` into the top-level
outbox column. Inject an application secret to resist offline guessing, or
implement `AggregateIdStrategyInterface` for a domain-specific grouping policy.

The default config-plugin factory is configured through application params:

```php
'rasuvaeff/yii3-ab-testing-outbox' => [
    'aggregateIdSecret' => $_ENV['AB_AGGREGATE_SECRET'],
],
```

Analytics dimensions are no longer configured here — the allow-list lives on the
`AbTesting` facade, so it applies to every delivery path rather than this one.

The event id comes from the event itself: the core mints it, the tracker writes
it into the payload **and** passes it to `Outbox::record(id: ...)`. The two must
agree, because the exporter may fill the ClickHouse `event_id` column from
either, and a mismatch splits one event into two rows that never deduplicate.

### ClickHouse routing

This package ships a compatible v1 ClickHouse schema in `migrations/` and a
matching `AbTestingClickHouseRoutes::map()`, which takes its tables and column
order from `yii3-ab-testing-clickhouse`'s `AnalyticsSchemaV2`. Its tables are
`ab_outbox_exposures` and `ab_outbox_conversions`, deliberately distinct from
the incompatible direct-sink tables. Two transport-meta columns lead each row:
`event_id` (filled by the exporter from the message id, for
`ReplacingMergeTree` dedup) and `event_at` (event time from the payload).

Apply the DDL with the same names passed to the route map:

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;

(new ClickHouseMigrationRunner(
    client: $clickHouseClient,
    migrationsPath: __DIR__ . '/vendor/rasuvaeff/yii3-ab-testing-outbox/migrations',
    placeholders: [
        'outbox_exposures_table' => AbTestingClickHouseRoutes::EXPOSURES_TABLE,
        'outbox_conversions_table' => AbTestingClickHouseRoutes::CONVERSIONS_TABLE,
    ],
))->run();

$router = new MapClickHouseMessageRouter(routes: AbTestingClickHouseRoutes::map());
```

The tables use `ReplacingMergeTree ORDER BY event_id`; at-least-once retries of
the same outbox message therefore collapse during ClickHouse merges. They also
store `ingested_at` separately from the payload's `event_at`.

Before 1.2.4 the route defaults were `ab_exposures` / `ab_conversions`, but this
package supplied no matching DDL and those names collided with the direct sink's
different schema. Existing applications that created their own compatible
tables under the old names can retain them by passing both names explicitly to
`map()`. Never route outbox rows into the direct package's tables.

### Yii3 DI

`config/di.php` binds `ExposureTracker` and `ConversionTracker`. Bind each from a
**single** source — installing this next to another tracker backend that also
binds them triggers a `yiisoft/config` `Duplicate key` error. To use several
sinks at once, compose them in your app config:

```php
use Rasuvaeff\Yii3AbTesting\CompositeExposureTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;

return [
    ExposureTracker::class => static fn (Outbox $outbox, LoggerInterface $log): ExposureTracker
        => new CompositeExposureTracker(new OutboxExposureTracker($outbox), new LoggerExposureTracker($log)),
];
```

## Security

- `subject_id` remains verbatim in the analytics JSON payload and may be PII.
  Pseudonymous aggregate ids only reduce its footprint in top-level outbox
  metadata; they do not anonymize the event. Pseudonymize before `Assignment`
  when the analytics payload itself must not contain the original identifier.
- Always inject a private aggregate-id secret in production. The empty-secret
  default is deterministic and prevents accidental raw-value disclosure, but
  does not resist dictionary attacks on predictable subject ids.
- Context attributes are denied by default. Use a short allow-list, avoid
  secrets and high-cardinality values, and redact before the payload reaches
  outbox storage.
- Payloads are JSON strings written through the outbox; `goal`/`experiment` are
  trusted analytics dimensions from your application.

## Examples

See [`examples/`](examples/).

## Development

```bash
make build
make test
make test-coverage
make mutation
vendor/bin/testo --suite=Integration # requires CLICKHOUSE_HOST; runs live in CI
```

See [AGENTS.md](AGENTS.md) for the monorepo-root Docker invocation (path repo).

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
