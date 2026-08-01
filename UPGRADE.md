# Upgrade guide

## 1.x → 2.0

The payload becomes the canonical analytics event of schema v2, and the routes
target the tables owned by `yii3-ab-testing-clickhouse`.

### Drain the queue before switching routes

This is the one step with a real failure mode. A message queued before the
upgrade carries payload v1, which has no `decision_reason` and no `dimensions`.
Routing it into the v2 tables fails on a missing field — after the worker has
already retried it.

Point the exporter at **both** maps until the v1 messages are gone:

```php
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;

$router = new MapClickHouseMessageRouter(
    routes: AbTestingClickHouseRoutes::map(),          // v2, new messages
    // …and keep a second exporter (or a merged map) on:
    // AbTestingClickHouseRoutes::legacyV1Map()        // v1, still queued
);
```

Once `SELECT count() FROM outbox WHERE status = 'pending'` is zero for the
`ab.exposure` and `ab.conversion` types, drop the legacy map.

### Trackers take events

```php
// 1.x
$tracker->trackExposure($assignment, $eventId);
$tracker->trackConversion($assignment, goal: 'purchase', eventId: $eventId);

// 2.0 — the facade mints the event and its identity
$event = $ab->trackExposure($assignment);   // the tracker is called for you
```

The `$eventId` argument is gone. The core mints `eventId` and the tracker passes
it to `Outbox::record(id: …)` *and* writes it into the payload, so the message
id and the payload field cannot disagree — which matters because the exporter
may fill the event-id column from either.

If you implemented `AbTestingOutboxMessageFactoryInterface` or
`AggregateIdStrategyInterface`, change the parameters to `ExposureEvent` /
`ConversionEvent`. The conversion goal now lives inside the event.

### The context allow-list moved to the core

```diff
-use Rasuvaeff\Yii3AbTestingOutbox\AllowListAnalyticsContextPolicy;
+use Rasuvaeff\Yii3AbTesting\AllowListAnalyticsContextPolicy;
```

Configure it on the `AbTesting` facade rather than in this package's params —
the `context` params key is gone. Applied at the facade it filters every
delivery path; applied here it filtered only the durable one.

```php
new AbTesting(
    // …
    contextPolicy: new AllowListAnalyticsContextPolicy(allowedAttributes: ['country']),
);
```

### Create the v2 tables

They belong to `yii3-ab-testing-clickhouse` now — this package no longer ships
DDL for them:

```php
(new Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations($client))->apply();
```

The v1 tables (`ab_outbox_exposures`, `ab_outbox_conversions`) and their
migrations stay, because the legacy route map still needs them while the queue
drains. They are not migrated into v2: their rows lack the v2 decision fields,
so a backfill would invent data.

### `SystemClock` is gone

Use `Rasuvaeff\Yii3AbTesting\SystemClock`, or any PSR-20 clock, on the facade.
Event time is stamped once, when the event is created — not when the message is
serialized, and not when the worker exports it.
