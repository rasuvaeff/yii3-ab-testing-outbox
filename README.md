# rasuvaeff/yii3-ab-testing-outbox

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-ab-testing-outbox/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-outbox)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-ab-testing-outbox/downloads)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-outbox)
[![Build](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-ab-testing-outbox/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-ab-testing-outbox)
[![License](https://poser.pugx.org/rasuvaeff/yii3-ab-testing-outbox/license)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-outbox)

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

## Installation

```bash
composer require rasuvaeff/yii3-ab-testing-outbox
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
{"experiment":"checkout","variant":"green","subject_id":"user-1","is_forced":0,"is_fallback":0,"is_sticky":0,"environment":"production"}
```

Conversions add `"goal"`. Flags are `0|1`; `environment` is always present.

### ClickHouse routing

`AbTestingClickHouseRoutes::map()` returns a ready-made route map for
`yii3-outbox-clickhouse`, with a leading `event_id` column the exporter fills from
the message id for `ReplacingMergeTree` dedup:

```php
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;

$router = new MapClickHouseMessageRouter(routes: AbTestingClickHouseRoutes::map());
```

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

- `subject_id` may be PII; this package never hashes it silently — privacy policy
  is the application's.
- Payloads are JSON strings written through the outbox; `goal`/`experiment` are
  trusted analytics dimensions from your application.

## Examples

See [`examples/`](examples/).

## Development

```bash
make build
```

See [AGENTS.md](AGENTS.md) for the monorepo-root Docker invocation (path repo).

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
