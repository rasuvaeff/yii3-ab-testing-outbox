<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;

#[CoversClass(AbTestingClickHouseRoutes::class)]
final class AbTestingClickHouseRoutesTest extends TestCase
{
    #[Test]
    public function mapsBothEventTypesWithEventIdFirst(): void
    {
        $map = AbTestingClickHouseRoutes::map();

        $this->assertSame(['ab.exposure', 'ab.conversion'], array_keys($map));
        $this->assertSame('ab_exposures', $map['ab.exposure']['table']);
        $this->assertSame('ab_conversions', $map['ab.conversion']['table']);
        $this->assertSame('event_id', $map['ab.exposure']['columns'][0]);
        $this->assertContains('goal', $map['ab.conversion']['columns']);
        $this->assertNotContains('goal', $map['ab.exposure']['columns']);
    }

    #[Test]
    public function honoursCustomTableAndColumnNames(): void
    {
        $map = AbTestingClickHouseRoutes::map(
            exposuresTable: 'exp',
            conversionsTable: 'conv',
            eventIdColumn: 'eid',
        );

        $this->assertSame('exp', $map['ab.exposure']['table']);
        $this->assertSame('conv', $map['ab.conversion']['table']);
        $this->assertSame('eid', $map['ab.exposure']['columns'][0]);
    }
}
