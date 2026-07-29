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
$exposureTracker->trackExposure($assignment);             // durable, no network call
// later, on the goal:
$conversionTracker->trackConversion($assignment, goal: 'purchase');
```

### Payload

`ab.exposure` / `ab.conversion` messages carry a JSON object whose field names
match the analytics columns of `yii3-ab-testing-clickhouse`:

```json
{"v":1,"event_at":"2026-06-12 10:00:00","experiment":"checkout","variant":"green","subject_id":"user-1","is_forced":0,"is_fallback":0,"is_sticky":0,"environment":"production"}
```

The leading `v` field is a transport-meta schema version (`DefaultAbTestingOutboxMessageFactory::PAYLOAD_VERSION`).
It is **not** listed in `AbTestingClickHouseRoutes` columns and is never written to ClickHouse — it exists
so downstream consumers reading raw outbox messages can detect payload schema generations.
Conversions add `"goal"`. Flags are `0|1`; `environment` is always present.
`event_at` is the event time (UTC `Y-m-d H:i:s`) stamped when tracked — distinct
from the worker's export time.

### ClickHouse routing

This package ships a compatible v1 ClickHouse schema in `migrations/` and a
matching `AbTestingClickHouseRoutes::map()`. Its default tables are
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

- **`subject_id` is written to two places, not one.** It is a field inside the
  JSON payload *and* a component of the outbox message's `aggregate_id`, which
  is a top-level column of the outbox table:

  | Message | `aggregate_id` |
  |---|---|
  | `ab.exposure` | `<experiment>:<subject_id>` |
  | `ab.conversion` | `<experiment>:<subject_id>:<goal>` |

  If `subject_id` is PII, so is that column. Anything that reads the outbox
  table without parsing payloads — an admin screen listing messages by
  aggregate, a log line, a metric label, a support export — exposes it. A
  redaction or retention policy applied only to the payload misses it.

- `subject_id` may be PII; this package never hashes it silently — privacy policy
  is the application's. Hash or pseudonymise it **before** it reaches
  `Assignment`, so that both the payload and the `aggregate_id` carry the same
  safe value. Hashing at the sink is too late: the outbox row already holds the
  original.
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
