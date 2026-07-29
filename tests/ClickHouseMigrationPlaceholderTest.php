<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

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
        $exposures = str_replace(
            '{{outbox_exposures_table}}',
            AbTestingClickHouseRoutes::EXPOSURES_TABLE,
            $this->read('0001_create_ab_outbox_exposures.sql'),
        );
        $conversions = str_replace(
            '{{outbox_conversions_table}}',
            AbTestingClickHouseRoutes::CONVERSIONS_TABLE,
            $this->read('0002_create_ab_outbox_conversions.sql'),
        );

        Assert::string($exposures)->contains('CREATE TABLE IF NOT EXISTS ab_outbox_exposures');
        Assert::string($conversions)->contains('CREATE TABLE IF NOT EXISTS ab_outbox_conversions');
        Assert::false(str_contains($exposures, 'EXISTS ab_exposures'));
        Assert::false(str_contains($conversions, 'EXISTS ab_conversions'));
    }

    public function ddlProvidesRouteColumnsAndEventIdDeduplication(): void
    {
        foreach (AbTestingClickHouseRoutes::map() as $type => $route) {
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
