<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Rasuvaeff\Yii3AbTesting\AssignmentSource;
use Rasuvaeff\Yii3AbTesting\DecisionReason;
use Rasuvaeff\Yii3AbTestingOutbox\DefaultAbTestingOutboxMessageFactory;
use Rasuvaeff\Yii3AbTestingOutbox\PseudonymousAggregateIdStrategy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(DefaultAbTestingOutboxMessageFactory::class)]
final class DefaultAbTestingOutboxMessageFactoryTest
{
    private DefaultAbTestingOutboxMessageFactory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new DefaultAbTestingOutboxMessageFactory();
    }

    public function buildsExposurePayload(): void
    {
        $payload = $this->factory->exposure(Events::exposure(
            dimensions: ['country' => 'RU'],
            experimentRevision: 'db:7',
            environment: 'production',
        ));

        Assert::same($payload->type, 'ab.exposure');
        Assert::true(is_string($payload->aggregateId));
        Assert::false(str_contains((string) $payload->aggregateId, 'user-1'));
        Assert::same($this->decode($payload->payload), [
            'v' => 2,
            'event_id' => 'evt-1',
            'occurred_at' => '2026-08-01 10:00:00.123',
            'experiment' => 'checkout',
            'variant' => 'green',
            'subject_id' => 'user-1',
            'decision_reason' => 'assigned',
            'assignment_source' => 'computed',
            'experiment_revision' => 'db:7',
            'environment' => 'production',
            'dimensions' => '{"country":"RU"}',
        ]);
    }

    public function buildsConversionPayloadWithGoal(): void
    {
        $payload = $this->factory->conversion(Events::conversion(
            goal: 'purchase',
            exposureEventId: 'evt-1',
        ));

        Assert::same($payload->type, 'ab.conversion');
        Assert::true(is_string($payload->aggregateId));
        Assert::false(str_contains((string) $payload->aggregateId, 'user-1'));
        Assert::same($this->decode($payload->payload), [
            'v' => 2,
            'event_id' => 'evt-2',
            'occurred_at' => '2026-08-01 10:00:00.123',
            'experiment' => 'checkout',
            'variant' => 'green',
            'subject_id' => 'user-1',
            'goal' => 'purchase',
            'decision_reason' => 'assigned',
            'assignment_source' => 'computed',
            'experiment_revision' => '',
            'environment' => '',
            'dimensions' => '{}',
            'exposure_event_id' => 'evt-1',
        ]);
    }

    /**
     * v1 stamped `event_at` from an injected clock at serialization time. v2
     * carries the event's own `occurred_at`, normalized to UTC when the event
     * was created: the ClickHouse partition key derives from it, so a retry
     * that re-stamped it would land in another partition and never deduplicate.
     */
    public function timeComesFromTheEventNormalizedToUtcNotFromSerialization(): void
    {
        $event = Events::exposure(
            occurredAt: new DateTimeImmutable('2026-06-12 15:00:00.000', new DateTimeZone('Europe/Berlin')),
        );

        Assert::same($this->decode($this->factory->exposure($event)->payload)['occurred_at'], '2026-06-12 13:00:00.000');

        // Serializing the same event twice cannot drift: no clock is consulted.
        Assert::same(
            $this->decode($this->factory->exposure($event)->payload)['occurred_at'],
            $this->decode($this->factory->exposure($event)->payload)['occurred_at'],
        );
    }

    /**
     * The v1 boolean flags (`is_forced`, `is_fallback`, `is_sticky`) collapsed
     * several questions into one axis; v2 carries the decision reason and the
     * source of the assignment as their own enum-backed strings.
     */
    #[DataProvider('decisionProvider')]
    public function serializesDecisionAndSourceAsEnumValues(
        DecisionReason $reason,
        AssignmentSource $source,
        string $expectedReason,
        string $expectedSource,
    ): void {
        $fields = $this->decode($this->factory->exposure(Events::exposure(reason: $reason, source: $source))->payload);

        Assert::same($fields['decision_reason'], $expectedReason);
        Assert::same($fields['assignment_source'], $expectedSource);
    }

    public static function decisionProvider(): iterable
    {
        yield 'assigned, computed' => [DecisionReason::Assigned, AssignmentSource::Computed, 'assigned', 'computed'];
        yield 'forced, store' => [DecisionReason::Forced, AssignmentSource::Store, 'forced', 'store'];
        // v1 collapsed both of these into the single `is_fallback` flag.
        yield 'fallback disabled' => [
            DecisionReason::FallbackDisabled,
            AssignmentSource::Computed,
            'fallback_disabled',
            'computed',
        ];
        yield 'fallback targeting mismatch' => [
            DecisionReason::FallbackTargetingMismatch,
            AssignmentSource::Computed,
            'fallback_targeting_mismatch',
            'computed',
        ];
    }

    public function absentOptionalValuesSerializeAsEmptyStrings(): void
    {
        $fields = $this->decode($this->factory->exposure(Events::exposure())->payload);

        Assert::same($fields['environment'], '');
        Assert::same($fields['experiment_revision'], '');

        // Never the empty string: the column default and a written value must
        // stay tellable apart.
        Assert::same($fields['dimensions'], '{}');
    }

    public function payloadVersionIsFirstField(): void
    {
        $fields = $this->decode($this->factory->exposure(Events::exposure())->payload);

        Assert::same($fields['v'], 2);
        Assert::same(array_key_first($fields), 'v');
        Assert::same(DefaultAbTestingOutboxMessageFactory::PAYLOAD_VERSION, 2);
    }

    /**
     * v1 filtered analytics context here, through an allow-list policy. The
     * filtering moved to the core facade in 2.0 — the event arrives already
     * filtered — so what this package owes the exporter is the encoding: a flat
     * JSON string with sorted keys, never a nested object.
     */
    public function serializesDimensionsAsAJsonStringWithSortedKeys(): void
    {
        $payload = $this->factory->exposure(Events::exposure(dimensions: ['tier' => 'gold', 'country' => 'RU']));

        $dimensions = $this->decode($payload->payload)['dimensions'];
        Assert::true(is_string($dimensions));
        Assert::same($dimensions, '{"country":"RU","tier":"gold"}');
    }

    public function aggregateIdsComeFromTheInjectedStrategy(): void
    {
        $event = Events::exposure();
        $withSecret = new DefaultAbTestingOutboxMessageFactory(
            aggregateIdStrategy: new PseudonymousAggregateIdStrategy(secret: 'application-secret'),
        );

        Assert::false($this->factory->exposure($event)->aggregateId === $withSecret->exposure($event)->aggregateId);
        Assert::same(
            $withSecret->exposure($event)->aggregateId,
            (new PseudonymousAggregateIdStrategy(secret: 'application-secret'))->exposure($event),
        );
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
