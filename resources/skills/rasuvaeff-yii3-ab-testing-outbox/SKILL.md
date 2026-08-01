---
name: rasuvaeff-yii3-ab-testing-outbox
description: >-
  Durable delivery of Yii3 A/B testing events with
  rasuvaeff/yii3-ab-testing-outbox — outbox trackers, payload v2, ClickHouse
  route maps and aggregate-id policy. Use when writing, reviewing or debugging
  event delivery, the outbox payload, exporter routing, or the v1→v2 migration
  window in a project that has this package installed.
---

# rasuvaeff/yii3-ab-testing-outbox

A thin **producer**: turns core events into durable `yii3-outbox` messages. A
worker drains them later. Namespace `Rasuvaeff\Yii3AbTestingOutbox\`.

It does NOT export to ClickHouse, read the outbox, run a worker, or batch.

## Safety rules — verify these on every change

1. **The payload is core's canonical row, not ours.** Build it with
   `EventSerializer`, never by hand. Assembling it locally is exactly how the
   direct sink and the outbox came to drop different fields in v1, with nothing
   to notice until the data was already wrong.

2. **Do not restate the schema.** Routes come from
   `yii3-ab-testing-clickhouse`'s `AnalyticsSchemaV2`, which that package pins to
   its own DDL. A local copy of table names or column order is two sources of
   truth that disagree, surfacing as a failed insert on a clean install.

3. **`event_id` in the payload must equal the outbox message id.** The tracker
   writes it to both. The exporter may fill the ClickHouse event-id column from
   either, so a mismatch splits one event into two rows that never deduplicate.

4. **`occurred_at` comes from the event, never from a clock here.** A retry
   serialized later must carry the original instant: the partition key derives
   from it, and a re-stamped event lands in a different partition where
   deduplication cannot reach it.

5. **Trackers are NOT flushable.** `Outbox::record()` persists immediately;
   there is no request-local buffer. Do not implement `FlushableTracker`.

6. **Aggregate ids must never contain a raw `subject_id`.** The default strategy
   is pseudonymous; inject a private secret in production or offline guessing
   from an experiment name plus a subject id is practical.

## The v1 → v2 migration window is the sharp edge

A message queued before the upgrade carries payload v1, which has **no**
`decision_reason` and **no** `dimensions`. Routing it into the v2 tables fails
on a missing field — after the worker has already retried it.

Keep `AbTestingClickHouseRoutes::legacyV1Map()` wired until the queue drains,
then drop it. The two generations never share a table name, so nothing collides
while both maps are active.

## Canonical usage

```php
$ab = new AbTesting(
    // …
    exposureTracker: new OutboxExposureTracker($outbox),
    conversionTracker: new OutboxConversionTracker($outbox),
    // The allow-list lives on the facade, so every delivery path filters
    // identically; configured per-adapter it would cover this one only.
    contextPolicy: new AllowListAnalyticsContextPolicy(allowedAttributes: ['country']),
);

$router = new MapClickHouseMessageRouter(routes: AbTestingClickHouseRoutes::map());
```

## Full API

`vendor/rasuvaeff/yii3-ab-testing-outbox/llms.txt`. Upgrading:
`vendor/rasuvaeff/yii3-ab-testing-outbox/UPGRADE.md`.
