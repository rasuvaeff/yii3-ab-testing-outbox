<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(AbTestingClickHouseRoutes::class)]
final class AbTestingClickHouseRoutesTest
{
    public function mapsBothEventTypesWithEventIdFirst(): void
    {
        $map = AbTestingClickHouseRoutes::map();

        Assert::same(array_keys($map), ['ab.exposure', 'ab.conversion']);
        Assert::same($map['ab.exposure']['table'], 'ab_outbox_exposures');
        Assert::same($map['ab.conversion']['table'], 'ab_outbox_conversions');
        Assert::same($map['ab.exposure']['columns'][0], 'event_id');
        Assert::same($map['ab.exposure']['columns'][1], 'event_at');
        Assert::same($map['ab.conversion']['columns'][0], 'event_id');
        Assert::true(in_array('event_at', $map['ab.conversion']['columns'], true));
        Assert::true(in_array('goal', $map['ab.conversion']['columns'], true));
        Assert::false(in_array('goal', $map['ab.exposure']['columns'], true));
    }

    public function doesNotExposeVersionFieldAsClickHouseColumn(): void
    {
        $map = AbTestingClickHouseRoutes::map();

        Assert::false(in_array('v', $map['ab.exposure']['columns'], true));
        Assert::false(in_array('v', $map['ab.conversion']['columns'], true));
    }

    public function honoursCustomTableAndColumnNames(): void
    {
        $map = AbTestingClickHouseRoutes::map(
            exposuresTable: 'exp',
            conversionsTable: 'conv',
            eventIdColumn: 'eid',
        );

        Assert::same($map['ab.exposure']['table'], 'exp');
        Assert::same($map['ab.conversion']['table'], 'conv');
        Assert::same($map['ab.exposure']['columns'][0], 'eid');
    }
}
