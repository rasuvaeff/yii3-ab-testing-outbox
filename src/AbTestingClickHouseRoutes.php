<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use Rasuvaeff\Yii3AbTestingClickHouse\AnalyticsSchemaV2;

/**
 * Route map for `yii3-outbox-clickhouse`, targeting the canonical schema v2.
 *
 * The tables and the column order are not restated here — they come from
 * {@see AnalyticsSchemaV2}, which `yii3-ab-testing-clickhouse` owns and pins to
 * its own DDL. A copy would be the v1 defect all over again: the route map and
 * the migration each claimed a truth, they disagreed, and the mismatch showed
 * up as a failed insert on a clean install.
 *
 * Returns a plain array — this class adds no ClickHouse client dependency.
 * `yii3-ab-testing-clickhouse` is a `suggest`, not a `require`: a producer that
 * routes messages somewhere other than ClickHouse should not have to install
 * the schema package, and this class is simply not loadable without it.
 *
 * @api
 */
final class AbTestingClickHouseRoutes
{
    /**
     * @return array<string, array{table: non-empty-string, columns: non-empty-list<string>}>
     */
    public static function map(): array
    {
        return [
            AbTestingOutboxEventType::Exposure->value => [
                'table' => AnalyticsSchemaV2::EXPOSURES_TABLE,
                'columns' => AnalyticsSchemaV2::EXPOSURE_COLUMNS,
            ],
            AbTestingOutboxEventType::Conversion->value => [
                'table' => AnalyticsSchemaV2::CONVERSIONS_TABLE,
                'columns' => AnalyticsSchemaV2::CONVERSION_COLUMNS,
            ],
        ];
    }

    /**
     * Routes for payload v1 messages still queued when the application upgrades.
     *
     * Point the exporter at both maps until the queue has drained: a v1 message
     * has neither `decision_reason` nor `dimensions`, so routing it into the v2
     * tables fails on a missing field — loudly, but only after the worker has
     * already retried it.
     *
     * The exporter fills the `event_id` column from the message id for these,
     * which is why they name it explicitly.
     *
     * @return array<string, array{table: non-empty-string, columns: non-empty-list<string>}>
     */
    public static function legacyV1Map(): array
    {
        return [
            AbTestingOutboxEventType::Exposure->value => [
                'table' => 'ab_outbox_exposures',
                'columns' => ['event_id', 'event_at', 'experiment', 'variant', 'subject_id', 'is_forced', 'is_fallback', 'is_sticky', 'environment'],
            ],
            AbTestingOutboxEventType::Conversion->value => [
                'table' => 'ab_outbox_conversions',
                'columns' => ['event_id', 'event_at', 'experiment', 'variant', 'subject_id', 'goal', 'is_forced', 'is_fallback', 'is_sticky', 'environment'],
            ],
        ];
    }
}
