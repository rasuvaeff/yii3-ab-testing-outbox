<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;

/**
 * Decision 4 guard: the analytics columns in {@see AbTestingClickHouseRoutes}
 * must match the single source of truth — the `COLUMNS` constants of
 * `yii3-ab-testing-clickhouse`. Skipped unless that package is installed
 * (it is not a hard dependency of the producer), so the column lists are only
 * compared when both are present.
 */
#[CoversNothing]
final class ClickHouseRoutesContractTest extends TestCase
{
    private const string EXPOSURE_SINK = 'Rasuvaeff\\Yii3AbTestingClickHouse\\ClickHouseExposureTracker';

    private const string CONVERSION_SINK = 'Rasuvaeff\\Yii3AbTestingClickHouse\\ClickHouseConversionTracker';

    #[\Override]
    protected function setUp(): void
    {
        if (!class_exists(self::EXPOSURE_SINK) || !class_exists(self::CONVERSION_SINK)) {
            $this->markTestSkipped('yii3-ab-testing-clickhouse is not installed; skipping the SoT column contract check.');
        }
    }

    #[Test]
    public function exposureColumnsMatchSinkSourceOfTruth(): void
    {
        $sinkClass = self::EXPOSURE_SINK;
        /** @var list<string> $sinkColumns */
        $sinkColumns = $sinkClass::COLUMNS;

        $this->assertSame($sinkColumns, $this->analyticColumns('ab.exposure'));
    }

    #[Test]
    public function conversionColumnsMatchSinkSourceOfTruth(): void
    {
        $sinkClass = self::CONVERSION_SINK;
        /** @var list<string> $sinkColumns */
        $sinkColumns = $sinkClass::COLUMNS;

        $this->assertSame($sinkColumns, $this->analyticColumns('ab.conversion'));
    }

    /**
     * Route columns with the leading transport `event_id` dropped.
     *
     * @return list<string>
     */
    private function analyticColumns(string $type): array
    {
        $columns = AbTestingClickHouseRoutes::map()[$type]['columns'];

        return array_values(array_filter($columns, static fn(string $column): bool => $column !== 'event_id'));
    }
}
