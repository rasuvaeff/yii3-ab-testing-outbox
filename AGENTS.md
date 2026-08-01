# AGENTS.md — yii3-ab-testing-outbox

Guidance for AI agents working on this package. Read before changing code.

## What this is

A thin **producer** adapter: it turns `yii3-ab-testing` exposure/conversion
events into durable `yii3-outbox` messages. A worker (with
`yii3-outbox-clickhouse` or another transport) drains the outbox later. Namespace:
`Rasuvaeff\Yii3AbTestingOutbox`.

Public API: `OutboxExposureTracker`, `OutboxConversionTracker`,
`AbTestingOutboxMessageFactoryInterface` + `DefaultAbTestingOutboxMessageFactory`,
`AbTestingOutboxPayload`, `AbTestingOutboxEventType`, `AbTestingClickHouseRoutes`,
and the `AggregateIdStrategyInterface` extension point.

The context allow-list and the clock moved to the **core** in 2.0. Applied at
the facade they filter every delivery path; applied here they filtered only the
durable one.

It does NOT export to ClickHouse, read the outbox, run a worker, or batch — those
belong downstream. It only serializes domain events into outbox messages.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Do not restate the schema.** Routes come from
   `yii3-ab-testing-clickhouse`'s `AnalyticsSchemaV2`, which that package pins
   to its own DDL. A local copy of table names or column order is the v1 defect
   returning: two sources of truth, disagreeing, surfacing as a failed insert on
   a clean install. Column ORDER matters — an INSERT is positional.
4. **Trackers are NOT flushable.** `Outbox::record()` persists immediately; there
   is no request-local buffer. Do not implement `FlushableTracker`.
5. **The payload is core's canonical row, not ours.** Build it with
   `EventSerializer`, never by hand: assembling it locally is exactly how the
   direct sink and the outbox came to drop different fields in v1.
6. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image. Core
`yii3-outbox` is consumed via a path repository while unpublished, so mount the
**monorepo root**:

```bash
# install (inject path repo with a version override, then revert + drop the lock)
docker run --rm -v "$REPO_ROOT":/repo -w /repo/yii3-ab-testing-outbox composer:2 sh -c '
  git config --global --add safe.directory "*";
  composer config repositories.core "{\"type\":\"path\",\"url\":\"../yii3-outbox\",\"options\":{\"versions\":{\"rasuvaeff/yii3-outbox\":\"1.0.0\"}}}";
  composer update -q;
  git checkout composer.json;
  rm -f composer.lock'

# build
docker run --rm -v "$REPO_ROOT":/repo -w /repo/yii3-ab-testing-outbox composer:2 composer build
```

`rasuvaeff/yii3-ab-testing` resolves from Packagist (published). `composer.json`
keeps a clean Packagist `^1.0` constraint for `yii3-outbox` with no committed
`repositories` block. GitHub CI is red until `yii3-outbox` is on Packagist —
expected. `composer.lock` is gitignored (library).

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

The `ClickHouseRoutesContractTest` compares against the dev-installed
`yii3-ab-testing-clickhouse`; do not make that check optional again. The durable
pipeline integration is separate and always gets a live ClickHouse service in
CI.
- E2E dev dependencies pull `yiisoft/db-sqlite`, so every CI job needs
  `mbstring`; the live integration job additionally needs `pdo_sqlite`.

## Invariants & gotchas

- Message types are fixed `ab.exposure` / `ab.conversion`
  (`AbTestingOutboxEventType`).
- v2 routes target `ab_exposures_v2` / `ab_conversions_v2`, owned by
  `yii3-ab-testing-clickhouse`. The v1 tables `ab_outbox_exposures` /
  `ab_outbox_conversions` and their DDL in `migrations/` stay only so the legacy
  route map can drain what was queued before the upgrade.
- The payload carries `decision_reason` and `assignment_source` instead of the
  v1 boolean flags, and `dimensions` as a JSON **string** rather than a nested
  object — the exporter rejects non-scalar payload fields.
- `occurred_at` is stamped once by the core facade, when the event is created —
  not when the message is serialized and not when the worker exports it. A retry
  must carry the original value: the ClickHouse partition key is derived from
  it, so a re-stamped event lands in a different partition and cannot be
  deduplicated.
- Aggregate ids are pseudonymous by default and must never contain raw
  `subject_id`. Inject a private secret in production or a custom
  `AggregateIdStrategyInterface`.

- `config/di.php` builds one message factory from the `aggregateIdSecret` param
  and injects it into both trackers.

- Payload v1 and v2 must coexist during rollout, and the failure mode is
  concrete: a v1 message has no `decision_reason` and no `dimensions`, so
  routing it into the v2 tables fails on a missing field — after the worker has
  already retried it. Keep `AbTestingClickHouseRoutes::legacyV1Map()` wired
  until the queue has drained, then drop it. Never change v1 field meaning.
- `event_id` is written into the payload AND passed to `Outbox::record(id: …)`.
  They must stay equal: the exporter may fill the event-id column from either,
  and a mismatch splits one event into two rows that never deduplicate.
- One-source rule: `config/di.php` binds `ExposureTracker` + `ConversionTracker`.
  Since 2.0 `yii3-ab-testing-clickhouse` binds neither (it stopped being a
  writer), so that particular collision is gone; installing a *different*
  tracker backend alongside this one still requires composing them with
  `CompositeExposureTracker` / `CompositeConversionTracker` in the app config.
- `tests/Integration/DurableClickHousePipelineTest` is the clean-install proof:
  tracker -> SQLite `DbOutboxStorage` -> exporter -> live ClickHouse. CI must set
  `CLICKHOUSE_HOST`, so this path cannot pass by skipping.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment
  (e.g. `actions/checkout@<sha> # v4`). Never revert to floating `@vN` tags.
  Updates go through Dependabot, which bumps the SHA and preserves the comment.
  Workflows also carry `permissions: { contents: read }` at workflow level and
  `persist-credentials: false` on every `actions/checkout` step. Verify with
  `zizmor --persona=auditor .github/` — must report no `unpinned-uses`,
  `excessive-permissions`, or `artipacked` findings.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build` (monorepo-root mount). Paste the output.
