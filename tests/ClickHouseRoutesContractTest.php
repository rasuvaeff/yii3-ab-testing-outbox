<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;
use Rasuvaeff\Yii3AbTestingOutbox\DefaultAbTestingOutboxMessageFactory;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * The producer half of the schema contract: what the factory writes must be
 * exactly what the routes insert, in the same order — an INSERT is positional,
 * and the exporter rejects a message whose payload lacks a routed column.
 *
 * In v1 this compared against the `COLUMNS` constants of the direct
 * ClickHouse sink. That sink no longer writes (both delivery paths now go
 * through the canonical serializer), so the check that still has teeth is
 * payload-versus-route; the routes themselves are compared to
 * `AnalyticsSchemaV2` in {@see AbTestingClickHouseRoutesTest}.
 *
 * It runs in the Unit suite deliberately: it never needed a server, and in the
 * Integration suite `composer build` never executed it.
 */
#[Test]
#[CoversNothing]
final class ClickHouseRoutesContractTest
{
    public function exposurePayloadFieldsMatchTheRouteColumns(): void
    {
        $payload = (new DefaultAbTestingOutboxMessageFactory())->exposure(Events::exposure());

        Assert::same(
            $this->routedFields($payload->payload),
            AbTestingClickHouseRoutes::map()['ab.exposure']['columns'],
        );
    }

    public function conversionPayloadFieldsMatchTheRouteColumns(): void
    {
        $payload = (new DefaultAbTestingOutboxMessageFactory())->conversion(Events::conversion());

        Assert::same(
            $this->routedFields($payload->payload),
            AbTestingClickHouseRoutes::map()['ab.conversion']['columns'],
        );
    }

    /**
     * Every payload value must be a scalar: the exporter refuses nested fields,
     * which is why `dimensions` travels as a JSON string.
     */
    public function everyPayloadFieldIsScalar(): void
    {
        $factory = new DefaultAbTestingOutboxMessageFactory();

        foreach ([
            $factory->exposure(Events::exposure(dimensions: ['country' => 'RU'])),
            $factory->conversion(Events::conversion(dimensions: ['country' => 'RU'])),
        ] as $payload) {
            foreach ($this->decode($payload->payload) as $field => $value) {
                Assert::true(is_scalar($value), sprintf('Field "%s" must be scalar', $field));
            }
        }
    }

    /**
     * Payload field names in wire order, minus the schema version, which is the
     * one field with no column of its own.
     *
     * @return list<string>
     */
    private function routedFields(string $json): array
    {
        return array_values(array_filter(
            array_keys($this->decode($json)),
            static fn(string $field): bool => $field !== 'v',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
