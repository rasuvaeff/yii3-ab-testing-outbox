<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTestingOutbox\DefaultAbTestingOutboxMessageFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
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
        $this->factory = new DefaultAbTestingOutboxMessageFactory(
            clock: new FakeClock(new \DateTimeImmutable('2026-06-12 10:00:00', new \DateTimeZone('UTC'))),
        );
    }

    public function buildsExposurePayload(): void
    {
        $payload = $this->factory->exposure(new Assignment(
            experiment: 'checkout',
            variant: 'green',
            subjectId: 'user-1',
            context: AssignmentContext::forEnvironment('production'),
        ));

        Assert::same($payload->type, 'ab.exposure');
        Assert::true(is_string($payload->aggregateId));
        Assert::false(str_contains($payload->aggregateId, 'user-1'));
        Assert::same($this->decode($payload->payload), [
            'v' => 1,
            'event_at' => '2026-06-12 10:00:00',
            'experiment' => 'checkout',
            'variant' => 'green',
            'subject_id' => 'user-1',
            'is_forced' => 0,
            'is_fallback' => 0,
            'is_sticky' => 0,
            'environment' => 'production',
            'context' => [],
        ]);
    }

    public function buildsConversionPayloadWithGoal(): void
    {
        $payload = $this->factory->conversion(
            new Assignment(experiment: 'checkout', variant: 'green', subjectId: 'user-1'),
            goal: 'purchase',
        );

        Assert::same($payload->type, 'ab.conversion');
        Assert::true(is_string($payload->aggregateId));
        Assert::false(str_contains($payload->aggregateId, 'user-1'));
        Assert::same($this->decode($payload->payload), [
            'v' => 1,
            'event_at' => '2026-06-12 10:00:00',
            'experiment' => 'checkout',
            'variant' => 'green',
            'subject_id' => 'user-1',
            'is_forced' => 0,
            'is_fallback' => 0,
            'is_sticky' => 0,
            'environment' => '',
            'context' => [],
            'goal' => 'purchase',
        ]);
    }

    public function stampsEventTimeFromClockNormalizedToUtc(): void
    {
        $factory = new DefaultAbTestingOutboxMessageFactory(
            clock: new FakeClock(new \DateTimeImmutable('2026-06-12 15:00:00', new \DateTimeZone('Europe/Berlin'))),
        );

        $payload = $factory->exposure(new Assignment(experiment: 'e', variant: 'a', subjectId: 'u'));

        Assert::same($this->decode($payload->payload)['event_at'], '2026-06-12 13:00:00');
    }

    public function serializesFlagsAsInts(): void
    {
        $payload = $this->factory->exposure(new Assignment(
            experiment: 'e',
            variant: 'a',
            subjectId: 'u',
            isForced: true,
            isFallback: true,
            isSticky: true,
        ));

        $fields = $this->decode($payload->payload);
        Assert::same($fields['is_forced'], 1);
        Assert::same($fields['is_fallback'], 1);
        Assert::same($fields['is_sticky'], 1);
    }

    public function environmentDefaultsToEmptyStringWithoutContext(): void
    {
        $payload = $this->factory->exposure(new Assignment(experiment: 'e', variant: 'a', subjectId: 'u'));

        Assert::same($this->decode($payload->payload)['environment'], '');
    }

    public function payloadVersionIsFirstField(): void
    {
        $payload = $this->factory->exposure(new Assignment(experiment: 'e', variant: 'a', subjectId: 'u'));

        $fields = $this->decode($payload->payload);
        Assert::same($fields['v'], 1);
        Assert::same(array_key_first($fields), 'v');
    }

    public function serializesOnlyContextAllowedByThePolicy(): void
    {
        $factory = new DefaultAbTestingOutboxMessageFactory(
            clock: new FakeClock(new \DateTimeImmutable('2026-06-12 10:00:00')),
            contextPolicy: new \Rasuvaeff\Yii3AbTestingOutbox\AllowListAnalyticsContextPolicy(
                allowedAttributes: ['country', 'email'],
                renamedAttributes: ['country' => 'market'],
                redactedAttributes: ['email'],
            ),
        );
        $context = AssignmentContext::empty()
            ->withAttribute('country', 'DE')
            ->withAttribute('email', 'person@example.com')
            ->withAttribute('internal_note', 'secret');

        $payload = $factory->exposure(new Assignment(
            experiment: 'e',
            variant: 'a',
            subjectId: 'u',
            context: $context,
        ));

        Assert::same($this->decode($payload->payload)['context'], [
            'market' => 'DE',
            'email' => '[redacted]',
        ]);
    }

    public function rejectsEmptyConversionGoal(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->factory->conversion(new Assignment(experiment: 'e', variant: 'a', subjectId: 'u'), goal: '');
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
