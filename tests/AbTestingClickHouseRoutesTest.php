<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use Rasuvaeff\Yii3AbTestingClickHouse\AnalyticsSchemaV2;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(AbTestingClickHouseRoutes::class)]
final class AbTestingClickHouseRoutesTest
{
    /**
     * The routes must not restate the schema — they take it from the package
     * that owns the DDL. A local copy is the v1 defect returning: two sources of
     * truth, disagreeing, surfacing as a failed insert on a clean install.
     */
    public function routesComeFromTheSchemaOwnerVerbatim(): void
    {
        $map = AbTestingClickHouseRoutes::map();

        Assert::same(array_keys($map), ['ab.exposure', 'ab.conversion']);
        Assert::same($map['ab.exposure']['table'], AnalyticsSchemaV2::EXPOSURES_TABLE);
        Assert::same($map['ab.conversion']['table'], AnalyticsSchemaV2::CONVERSIONS_TABLE);
        Assert::same($map['ab.exposure']['columns'], AnalyticsSchemaV2::EXPOSURE_COLUMNS);
        Assert::same($map['ab.conversion']['columns'], AnalyticsSchemaV2::CONVERSION_COLUMNS);
    }

    /**
     * Column order is not cosmetic: an INSERT lists columns positionally, so a
     * reordered map writes a variant into the subject column with no error.
     */
    public function columnOrderMatchesTheSchemaExactly(): void
    {
        $map = AbTestingClickHouseRoutes::map();

        Assert::same($map['ab.exposure']['columns'][0], 'event_id');
        Assert::same($map['ab.exposure']['columns'][1], 'occurred_at');
        Assert::same(
            array_values(array_diff($map['ab.conversion']['columns'], $map['ab.exposure']['columns'])),
            ['goal', 'exposure_event_id'],
        );
    }

    /**
     * `v` is transport meta for consumers reading raw messages, and
     * `ingested_at` is filled by the server. Routing either would overwrite the
     * table's own clock or fail on an unknown column.
     */
    public function transportMetaAndServerFieldsAreNotColumns(): void
    {
        $map = AbTestingClickHouseRoutes::map();

        foreach (['ab.exposure', 'ab.conversion'] as $type) {
            Assert::false(\in_array('v', $map[$type]['columns'], true));
            Assert::false(\in_array('ingested_at', $map[$type]['columns'], true));
        }
    }

    /**
     * A v1 message has neither `decision_reason` nor `dimensions`, so routing it
     * into the v2 tables fails on a missing field — after the worker has already
     * retried it. The legacy map exists so a queue can drain.
     */
    public function theLegacyMapStillTargetsTheV1Tables(): void
    {
        $map = AbTestingClickHouseRoutes::legacyV1Map();

        Assert::same($map['ab.exposure']['table'], 'ab_outbox_exposures');
        Assert::same($map['ab.conversion']['table'], 'ab_outbox_conversions');

        // Pinned in full: these columns describe tables that already exist in
        // production, so a dropped or reordered one is an insert failure on a
        // queue that is mid-drain — the worst possible moment.
        Assert::same($map['ab.exposure']['columns'], [
            'event_id', 'event_at', 'experiment', 'variant', 'subject_id',
            'is_forced', 'is_fallback', 'is_sticky', 'environment',
        ]);
        Assert::same($map['ab.conversion']['columns'], [
            'event_id', 'event_at', 'experiment', 'variant', 'subject_id', 'goal',
            'is_forced', 'is_fallback', 'is_sticky', 'environment',
        ]);
    }

    public function theTwoGenerationsNeverShareATable(): void
    {
        $v2 = AbTestingClickHouseRoutes::map();
        $v1 = AbTestingClickHouseRoutes::legacyV1Map();

        foreach (['ab.exposure', 'ab.conversion'] as $type) {
            Assert::false($v1[$type]['table'] === $v2[$type]['table']);
        }
    }
}
