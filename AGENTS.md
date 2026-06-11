# AGENTS.md — yii3-ab-testing-outbox

Guidance for AI agents working on this package. Read before changing code.

## What this is

A thin **producer** adapter: it turns `yii3-ab-testing` exposure/conversion
events into durable `yii3-outbox` messages. A worker (with
`yii3-outbox-clickhouse` or another transport) drains the outbox later. Namespace:
`Rasuvaeff\Yii3AbTestingOutbox`.

Public API: `OutboxExposureTracker`, `OutboxConversionTracker`,
`AbTestingOutboxMessageFactoryInterface` + `DefaultAbTestingOutboxMessageFactory`,
`AbTestingOutboxPayload`, `AbTestingOutboxEventType`, `AbTestingClickHouseRoutes`.

It does NOT export to ClickHouse, read the outbox, run a worker, or batch — those
belong downstream. It only serializes domain events into outbox messages.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Payload columns mirror the source of truth.** The analytics field names must
   match the `COLUMNS` of `yii3-ab-testing-clickhouse` (the SoT). The
   `ClickHouseRoutesContractTest` enforces it; update both together, never drift.
4. **Trackers are NOT flushable.** `Outbox::record()` persists immediately; there
   is no request-local buffer. Do not implement `FlushableTracker`.
5. **Preserve the public contract.** Update README + tests with any API change.

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

The `ClickHouseRoutesContractTest` only runs when `yii3-ab-testing-clickhouse` is
also installed (it is not a dependency); otherwise it skips.

## Invariants & gotchas

- Message types are fixed `ab.exposure` / `ab.conversion`
  (`AbTestingOutboxEventType`).
- Boolean flags serialize as int `0|1`; `environment` is always present
  (`''` without context); conversion adds `goal` (non-empty, validated).
- Aggregate id: `experiment:subject_id` (exposure),
  `experiment:subject_id:goal` (conversion) — diagnostics only, not part of the
  ClickHouse schema.
- One-source rule: `config/di.php` binds `ExposureTracker` + `ConversionTracker`.
  Installing this alongside another tracker backend (e.g.
  `yii3-ab-testing-clickhouse`) that also binds them is a `yiisoft/config`
  `Duplicate key` error — the app must compose them with
  `CompositeExposureTracker` / `CompositeConversionTracker` in its own config.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build` (monorepo-root mount). Paste the output.
