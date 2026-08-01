<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * The DDL shipped in `migrations/` creates the **v1** outbox tables. Since 2.0
 * they exist only so `legacyV1Map()` can drain messages queued before the
 * upgrade; the v2 tables are owned by `yii3-ab-testing-clickhouse`. This test
 * pins the shipped files to the legacy routes, which is the pair that still has
 * to agree.
 */
#[Test]
#[CoversNothing]
final class ClickHouseMigrationPlaceholderTest
{
    private const string MIGRATIONS_DIR = __DIR__ . '/../migrations';

    public function shippedDdlUsesDistinctOutboxTokens(): void
    {
        Assert::same($this->tokensIn('0001_create_ab_outbox_exposures.sql'), ['outbox_exposures_table']);
        Assert::same($this->tokensIn('0002_create_ab_outbox_conversions.sql'), ['outbox_conversions_table']);
    }

    public function defaultsResolveToTablesDistinctFromDirectSink(): void
    {
        $routes = AbTestingClickHouseRoutes::legacyV1Map();

        $exposures = str_replace(
            '{{outbox_exposures_table}}',
            $routes['ab.exposure']['table'],
            $this->read('0001_create_ab_outbox_exposures.sql'),
        );
        $conversions = str_replace(
            '{{outbox_conversions_table}}',
            $routes['ab.conversion']['table'],
            $this->read('0002_create_ab_outbox_conversions.sql'),
        );

        Assert::string($exposures)->contains('CREATE TABLE IF NOT EXISTS ab_outbox_exposures');
        Assert::string($conversions)->contains('CREATE TABLE IF NOT EXISTS ab_outbox_conversions');
        Assert::false(str_contains($exposures, 'EXISTS ab_exposures'));
        Assert::false(str_contains($conversions, 'EXISTS ab_conversions'));
    }

    public function ddlProvidesRouteColumnsAndEventIdDeduplication(): void
    {
        foreach (AbTestingClickHouseRoutes::legacyV1Map() as $type => $route) {
            $file = $type === 'ab.exposure'
                ? '0001_create_ab_outbox_exposures.sql'
                : '0002_create_ab_outbox_conversions.sql';
            $sql = $this->read($file);

            foreach ($route['columns'] as $column) {
                Assert::same(preg_match('/^\\s*' . preg_quote($column, '/') . '\\s+/m', $sql), 1);
            }

            Assert::string($sql)->contains('ENGINE = ReplacingMergeTree');
            Assert::string($sql)->contains('ORDER BY event_id');
        }
    }

    /**
     * The v2 tables are created by `yii3-ab-testing-clickhouse`, so nothing
     * here may claim them: two packages shipping DDL for one table is how the
     * route map and the migration came to disagree in the first place.
     */
    public function shippedDdlDoesNotClaimTheV2Tables(): void
    {
        foreach (AbTestingClickHouseRoutes::map() as $route) {
            foreach (['0001_create_ab_outbox_exposures.sql', '0002_create_ab_outbox_conversions.sql'] as $file) {
                Assert::false(str_contains($this->read($file), $route['table']));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tokensIn(string $file): array
    {
        preg_match_all('/\{\{([^}]+)}}/', $this->read($file), $matches);

        return array_values(array_unique($matches[1]));
    }

    private function read(string $file): string
    {
        $contents = file_get_contents(self::MIGRATIONS_DIR . '/' . $file);
        Assert::true($contents !== false);

        return $contents;
    }
}
