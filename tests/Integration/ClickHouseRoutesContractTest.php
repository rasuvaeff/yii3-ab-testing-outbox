<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests\Integration;

use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseConversionTracker;
use Rasuvaeff\Yii3AbTestingClickHouse\ClickHouseExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * Decision 4 guard: the analytics columns in {@see AbTestingClickHouseRoutes}
 * must match the single source of truth — the `COLUMNS` constants of
 * `yii3-ab-testing-clickhouse`, installed as a dev dependency so this contract
 * cannot silently skip.
 */
#[Test]
#[CoversNothing]
final class ClickHouseRoutesContractTest
{
    public function exposureColumnsMatchSinkSourceOfTruth(): void
    {
        Assert::same($this->analyticColumns('ab.exposure'), ClickHouseExposureTracker::COLUMNS);
    }

    public function conversionColumnsMatchSinkSourceOfTruth(): void
    {
        Assert::same($this->analyticColumns('ab.conversion'), ClickHouseConversionTracker::COLUMNS);
    }

    /**
     * Route columns with the transport-meta columns (`event_id`, `event_at`) dropped.
     *
     * @return list<string>
     */
    private function analyticColumns(string $type): array
    {
        $meta = ['event_id', 'event_at'];
        $columns = AbTestingClickHouseRoutes::map()[$type]['columns'];

        return array_values(array_filter($columns, static fn(string $column): bool => !\in_array($column, $meta, true)));
    }
}
