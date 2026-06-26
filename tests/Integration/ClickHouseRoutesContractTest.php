<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests\Integration;

use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Decision 4 guard: the analytics columns in {@see AbTestingClickHouseRoutes}
 * must match the single source of truth — the `COLUMNS` constants of
 * `yii3-ab-testing-clickhouse`. Skipped unless that package is installed
 * (it is not a hard dependency of the producer), so the column lists are only
 * compared when both are present.
 */
#[Test]
#[CoversNothing]
final class ClickHouseRoutesContractTest
{
    private const string EXPOSURE_SINK = 'Rasuvaeff\\Yii3AbTestingClickHouse\\ClickHouseExposureTracker';

    private const string CONVERSION_SINK = 'Rasuvaeff\\Yii3AbTestingClickHouse\\ClickHouseConversionTracker';

    #[BeforeTest]
    public function setUp(): void
    {
        if (!class_exists(self::EXPOSURE_SINK) || !class_exists(self::CONVERSION_SINK)) {
            Assert::true(true);

            return;
        }
    }

    public function exposureColumnsMatchSinkSourceOfTruth(): void
    {
        if (!class_exists(self::EXPOSURE_SINK)) {
            Assert::true(true);

            return;
        }

        $sinkClass = self::EXPOSURE_SINK;
        /** @var list<string> $sinkColumns */
        $sinkColumns = $sinkClass::COLUMNS;

        Assert::same($this->analyticColumns('ab.exposure'), $sinkColumns);
    }

    public function conversionColumnsMatchSinkSourceOfTruth(): void
    {
        if (!class_exists(self::CONVERSION_SINK)) {
            Assert::true(true);

            return;
        }

        $sinkClass = self::CONVERSION_SINK;
        /** @var list<string> $sinkColumns */
        $sinkColumns = $sinkClass::COLUMNS;

        Assert::same($this->analyticColumns('ab.conversion'), $sinkColumns);
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
