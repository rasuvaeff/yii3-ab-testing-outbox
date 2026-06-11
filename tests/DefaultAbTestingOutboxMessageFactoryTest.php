<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTestingOutbox\DefaultAbTestingOutboxMessageFactory;

#[CoversClass(DefaultAbTestingOutboxMessageFactory::class)]
final class DefaultAbTestingOutboxMessageFactoryTest extends TestCase
{
    private DefaultAbTestingOutboxMessageFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        $this->factory = new DefaultAbTestingOutboxMessageFactory();
    }

    #[Test]
    public function buildsExposurePayload(): void
    {
        $payload = $this->factory->exposure(new Assignment(
            experiment: 'checkout',
            variant: 'green',
            subjectId: 'user-1',
            context: AssignmentContext::forEnvironment('production'),
        ));

        $this->assertSame('ab.exposure', $payload->type);
        $this->assertSame('checkout:user-1', $payload->aggregateId);
        $this->assertSame([
            'experiment' => 'checkout',
            'variant' => 'green',
            'subject_id' => 'user-1',
            'is_forced' => 0,
            'is_fallback' => 0,
            'is_sticky' => 0,
            'environment' => 'production',
        ], $this->decode($payload->payload));
    }

    #[Test]
    public function buildsConversionPayloadWithGoal(): void
    {
        $payload = $this->factory->conversion(
            new Assignment(experiment: 'checkout', variant: 'green', subjectId: 'user-1'),
            goal: 'purchase',
        );

        $this->assertSame('ab.conversion', $payload->type);
        $this->assertSame('checkout:user-1:purchase', $payload->aggregateId);
        $this->assertSame([
            'experiment' => 'checkout',
            'variant' => 'green',
            'subject_id' => 'user-1',
            'is_forced' => 0,
            'is_fallback' => 0,
            'is_sticky' => 0,
            'environment' => '',
            'goal' => 'purchase',
        ], $this->decode($payload->payload));
    }

    #[Test]
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
        $this->assertSame(1, $fields['is_forced']);
        $this->assertSame(1, $fields['is_fallback']);
        $this->assertSame(1, $fields['is_sticky']);
    }

    #[Test]
    public function environmentDefaultsToEmptyStringWithoutContext(): void
    {
        $payload = $this->factory->exposure(new Assignment(experiment: 'e', variant: 'a', subjectId: 'u'));

        $this->assertSame('', $this->decode($payload->payload)['environment']);
    }

    #[Test]
    public function rejectsEmptyConversionGoal(): void
    {
        $this->expectException(InvalidArgumentException::class);

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
