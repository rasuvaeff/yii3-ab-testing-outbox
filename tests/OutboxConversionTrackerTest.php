<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\FlushableTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(OutboxConversionTracker::class)]
final class OutboxConversionTrackerTest
{
    private InMemoryStorage $storage;

    private OutboxConversionTracker $tracker;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = new InMemoryStorage();

        $this->tracker = new OutboxConversionTracker(new Outbox(
            storage: $this->storage,
            clock: new FakeClock(new \DateTimeImmutable('2026-06-11 12:00:00')),
        ));
    }

    public function recordsConversionAsOutboxMessage(): void
    {
        $this->tracker->trackConversion(
            new Assignment(experiment: 'checkout', variant: 'green', subjectId: 'user-1'),
            goal: 'purchase',
        );

        $pending = $this->storage->findPending();
        Assert::count($pending, 1);
        Assert::same($pending[0]->getType(), 'ab.conversion');
        Assert::string($pending[0]->getPayload())->contains('"goal":"purchase"');
        Assert::same($pending[0]->getAggregateId(), 'checkout:user-1:purchase');
    }

    public function isNotFlushable(): void
    {
        Assert::false($this->tracker instanceof FlushableTracker);
    }
}
