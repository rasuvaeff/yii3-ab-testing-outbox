<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use DateTimeImmutable;
use Rasuvaeff\Yii3AbTesting\FlushableTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

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
            clock: new StaticClock(new DateTimeImmutable('2026-06-11 12:00:00')),
        ));
    }

    public function recordsConversionAsOutboxMessage(): void
    {
        $this->tracker->trackConversion(Events::conversion(goal: 'purchase'));

        $pending = $this->storage->findPending();
        Assert::count($pending, 1);
        Assert::same($pending[0]->getType(), 'ab.conversion');
        Assert::string($pending[0]->getPayload())->contains('"goal":"purchase"');
        Assert::true(is_string($pending[0]->getAggregateId()));
        Assert::false(str_contains($pending[0]->getAggregateId(), 'user-1'));
    }

    public function isNotFlushable(): void
    {
        Assert::false($this->tracker instanceof FlushableTracker);
    }

    /**
     * v1 took an optional `$eventId`; v2 always uses the identity the core
     * already minted, so the message id and the payload's `event_id` cannot
     * disagree.
     */
    public function usesTheEventIdAsTheMessageId(): void
    {
        $this->tracker->trackConversion(Events::conversion(eventId: 'conversion-order-42'));

        $message = $this->storage->findPending()[0];
        Assert::same($message->getId(), 'conversion-order-42');
        Assert::string($message->getPayload())->contains('"event_id":"conversion-order-42"');
    }
}
