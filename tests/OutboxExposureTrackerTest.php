<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\FlushableTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(OutboxExposureTracker::class)]
final class OutboxExposureTrackerTest
{
    private InMemoryStorage $storage;

    private OutboxExposureTracker $tracker;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = new InMemoryStorage();

        $this->tracker = new OutboxExposureTracker(new Outbox(
            storage: $this->storage,
            clock: new FakeClock(new \DateTimeImmutable('2026-06-11 12:00:00')),
        ));
    }

    public function recordsExposureAsOutboxMessage(): void
    {
        $this->tracker->trackExposure(new Assignment(experiment: 'checkout', variant: 'green', subjectId: 'user-1'));

        $pending = $this->storage->findPending();
        Assert::count($pending, 1);
        Assert::same($pending[0]->getType(), 'ab.exposure');
        Assert::string($pending[0]->getPayload())->contains('"experiment":"checkout"');
        Assert::true(is_string($pending[0]->getAggregateId()));
        Assert::false(str_contains($pending[0]->getAggregateId(), 'user-1'));
    }

    public function isNotFlushable(): void
    {
        Assert::false($this->tracker instanceof FlushableTracker);
    }

    public function acceptsAStableDomainEventId(): void
    {
        $this->tracker->trackExposure(
            new Assignment(experiment: 'checkout', variant: 'green', subjectId: 'user-1'),
            eventId: 'exposure-order-42',
        );

        Assert::same($this->storage->findPending()[0]->getId(), 'exposure-order-42');
    }
}
