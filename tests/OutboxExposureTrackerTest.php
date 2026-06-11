<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\FlushableTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;

#[CoversClass(OutboxExposureTracker::class)]
final class OutboxExposureTrackerTest extends TestCase
{
    private InMemoryStorage $storage;

    private OutboxExposureTracker $tracker;

    #[\Override]
    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-06-11 12:00:00'));

        $this->tracker = new OutboxExposureTracker(new Outbox(storage: $this->storage, clock: $clock));
    }

    #[Test]
    public function recordsExposureAsOutboxMessage(): void
    {
        $this->tracker->trackExposure(new Assignment(experiment: 'checkout', variant: 'green', subjectId: 'user-1'));

        $pending = $this->storage->findPending();
        $this->assertCount(1, $pending);
        $this->assertSame('ab.exposure', $pending[0]->getType());
        $this->assertStringContainsString('"experiment":"checkout"', $pending[0]->getPayload());
        $this->assertSame('checkout:user-1', $pending[0]->getAggregateId());
    }

    #[Test]
    public function isNotFlushable(): void
    {
        $this->assertNotInstanceOf(FlushableTracker::class, $this->tracker);
    }
}
