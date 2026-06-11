<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\FlushableTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;

#[CoversClass(OutboxConversionTracker::class)]
final class OutboxConversionTrackerTest extends TestCase
{
    private InMemoryStorage $storage;

    private OutboxConversionTracker $tracker;

    #[\Override]
    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-06-11 12:00:00'));

        $this->tracker = new OutboxConversionTracker(new Outbox(storage: $this->storage, clock: $clock));
    }

    #[Test]
    public function recordsConversionAsOutboxMessage(): void
    {
        $this->tracker->trackConversion(
            new Assignment(experiment: 'checkout', variant: 'green', subjectId: 'user-1'),
            goal: 'purchase',
        );

        $pending = $this->storage->findPending();
        $this->assertCount(1, $pending);
        $this->assertSame('ab.conversion', $pending[0]->getType());
        $this->assertStringContainsString('"goal":"purchase"', $pending[0]->getPayload());
        $this->assertSame('checkout:user-1:purchase', $pending[0]->getAggregateId());
    }

    #[Test]
    public function isNotFlushable(): void
    {
        $this->assertNotInstanceOf(FlushableTracker::class, $this->tracker);
    }
}
