<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

/**
 * Route map for `yii3-outbox-clickhouse` targeting the compatible v1 DDL in
 * this package's `migrations/` directory. Returns a plain array — this class
 * adds no ClickHouse dependency.
 *
 * The analytics columns mirror those owned by `yii3-ab-testing-clickhouse`
 * (the single source of truth). Two transport-meta columns precede them:
 * `event_id` (filled by the exporter from the outbox message id, for
 * `ReplacingMergeTree` dedup) and `event_at` (the event time from the payload). A
 * contract test asserts the analytics columns stay in sync with the SoT.
 *
 * @api
 */
final class AbTestingClickHouseRoutes
{
    public const string EXPOSURES_TABLE = 'ab_outbox_exposures';

    public const string CONVERSIONS_TABLE = 'ab_outbox_conversions';

    /**
     * @param non-empty-string $exposuresTable
     * @param non-empty-string $conversionsTable
     * @param non-empty-string $eventIdColumn
     *
     * @return array<string, array{table: non-empty-string, columns: non-empty-list<string>}>
     */
    public static function map(
        string $exposuresTable = 'ab_exposures',
        string $conversionsTable = 'ab_conversions',
        string $eventIdColumn = 'event_id',
    ): array {
        // Keep the 1.2.3 signature defaults for reflection-level BC while
        // making the normal zero-argument call target the shipped v1 schema.
        if (\func_num_args() === 0) {
            $exposuresTable = self::EXPOSURES_TABLE;
            $conversionsTable = self::CONVERSIONS_TABLE;
        }

        return [
            AbTestingOutboxEventType::Exposure->value => [
                'table' => $exposuresTable,
                'columns' => [$eventIdColumn, 'event_at', 'experiment', 'variant', 'subject_id', 'is_forced', 'is_fallback', 'is_sticky', 'environment'],
            ],
            AbTestingOutboxEventType::Conversion->value => [
                'table' => $conversionsTable,
                'columns' => [$eventIdColumn, 'event_at', 'experiment', 'variant', 'subject_id', 'goal', 'is_forced', 'is_fallback', 'is_sticky', 'environment'],
            ],
        ];
    }
}
