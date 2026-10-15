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

> **Documentation site:** [rasuvaeff.github.io/yii3-ab-testing](https://rasuvaeff.github.io/yii3-ab-testing/)
> covers the whole family — including the
> [integration matrix](https://rasuvaeff.github.io/yii3-ab-testing/integration)
> for assembling a combination.

## Durable vs log shipping

The family has two delivery paths, and both write the same rows into the
schema v2 tables of `yii3-ab-testing-clickhouse`. This package is the durable
one.

| | Durable (this package) | Log shipping |
|---|---|---|
| Packages | `yii3-ab-testing-outbox` + `yii3-outbox-db` + `yii3-outbox-clickhouse` | core's `LoggerExposureTracker` / `LoggerConversionTracker` + Vector / Fluent Bit |
| Batching | large, cross-request, by the worker | by the collector |
| Survives a ClickHouse outage | yes | as far as the collector buffers |
| Setup | worker + outbox storage | a log pipeline you already run |

The direct ClickHouse writer that `yii3-ab-testing-clickhouse` 1.x shipped was
removed in 2.0: under PHP-FPM it inserted a few rows per request inside the
visitor's latency.

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^2.0
- `rasuvaeff/yii3-outbox` ^1.2
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
use Rasuvaeff\Yii3AbTestingOutbox\DefaultAbTestingOutboxMessageFactory;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\PseudonymousAggregateIdStrategy;
use Rasuvaeff\Yii3Outbox\Outbox;

$outbox = new Outbox(storage: $storage, clock: $clock);   // storage from yii3-outbox-db
$messageFactory = new DefaultAbTestingOutboxMessageFactory(
    aggregateIdStrategy: new PseudonymousAggregateIdStrategy(secret: $_ENV['AB_AGGREGATE_SECRET']),
);
$ab = new AbTesting(
    provider: $provider,
    strategy: $strategy,
    exposureTracker: new OutboxExposureTracker($outbox, $messageFactory),
    conversionTracker: new OutboxConversionTracker($outbox, $messageFactory),
);

$assignment = $ab->assign(experiment: 'checkout', subjectId: $userId);
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

The `PseudonymousAggregateIdStrategy` emits stable HMAC-SHA-256 ids such as
`exposure:<digest>` and never copies raw `subject_id` into the top-level outbox
column. Its constructor requires a non-empty application secret; an empty or
whitespace-only value throws `InvalidArgumentException`. Implement
`AggregateIdStrategyInterface` for a domain-specific grouping policy.

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

`AbTestingClickHouseRoutes::map()` is the route map for `yii3-outbox-clickhouse`.
It targets the schema v2 tables owned by `yii3-ab-testing-clickhouse` and takes
their names and column order from `AnalyticsSchemaV2` rather than restating
them — an `INSERT` lists columns positionally, so a copied list that drifted
would write values into the wrong columns without an error.

```php
use Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;

(new SchemaMigrations($clickHouseClient))->apply();   // creates ab_exposures_v2 / ab_conversions_v2

$router = new MapClickHouseMessageRouter(routes: AbTestingClickHouseRoutes::map());
```

The worker drains the outbox with `./yii outbox:clickhouse:export`.

`yii3-ab-testing-clickhouse` is a `suggest`, not a `require`: a producer that
routes messages somewhere other than ClickHouse does not have to install the
schema package, and `AbTestingClickHouseRoutes` is simply not loadable without
it.

**Upgrading from 1.x payloads.** Messages queued before the upgrade carry
payload v1, which has no `decision_reason` and no `dimensions`, so they cannot
go into the v2 tables. `AbTestingClickHouseRoutes::legacyV1Map()` routes them
into the v1 tables `ab_outbox_exposures` / `ab_outbox_conversions`, whose DDL
still ships in `migrations/` for exactly this. Run both maps until the queue has
drained — see [UPGRADE.md](UPGRADE.md).

### Yii3 DI

`config/di.php` binds `ExposureTracker` and `ConversionTracker`. Bind each from a
**single** source — installing this next to another tracker backend that also
binds them triggers a `yiisoft/config` `Duplicate key` error. The message
factory it injects is bound here too, built from the `aggregateIdSecret` param. To use several
sinks at once, compose them in your app config:

```php
use Psr\Log\LoggerInterface;
use Rasuvaeff\Yii3AbTesting\CompositeExposureTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTesting\LoggerExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingOutboxMessageFactoryInterface;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3Outbox\Outbox;

return [
    ExposureTracker::class => static fn (
        Outbox $outbox,
        AbTestingOutboxMessageFactoryInterface $messageFactory,
        LoggerInterface $log,
    ): ExposureTracker => new CompositeExposureTracker(
        new OutboxExposureTracker($outbox, $messageFactory),
        new LoggerExposureTracker($log),
    ),
];
```

## Security

- `subject_id` remains verbatim in the analytics JSON payload and may be PII.
  Pseudonymous aggregate ids only reduce its footprint in top-level outbox
  metadata; they do not anonymize the event. Pseudonymize before `Assignment`
  when the analytics payload itself must not contain the original identifier.
- Always inject a private, non-empty aggregate-id secret. The package fails fast
  during construction and config-plugin wiring when the secret is absent or
  blank, preventing an insecure deterministic default from reaching production.
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
