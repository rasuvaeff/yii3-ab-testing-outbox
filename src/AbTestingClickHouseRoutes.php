<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

/**
 * Ready-made route map for `yii3-outbox-clickhouse`, so a consumer does not
 * hand-write it. Returns a plain array — this class adds no ClickHouse
 * dependency.
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
