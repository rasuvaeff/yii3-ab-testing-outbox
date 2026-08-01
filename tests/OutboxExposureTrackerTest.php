<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use DateTimeImmutable;
use Rasuvaeff\Yii3AbTesting\FlushableTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

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
            clock: new StaticClock(new DateTimeImmutable('2026-06-11 12:00:00')),
        ));
    }

    public function recordsExposureAsOutboxMessage(): void
    {
        $this->tracker->trackExposure(Events::exposure());

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

    /**
     * v1 took an optional `$eventId`; v2 always uses the identity the core
     * already minted. The message id and the payload's `event_id` must stay
     * equal — the exporter may fill the event-id column from either, and a
     * mismatch splits one event into two rows that never deduplicate.
     */
    public function usesTheEventIdAsTheMessageId(): void
    {
        $this->tracker->trackExposure(Events::exposure(eventId: 'exposure-order-42'));

        $message = $this->storage->findPending()[0];
        Assert::same($message->getId(), 'exposure-order-42');
        Assert::string($message->getPayload())->contains('"event_id":"exposure-order-42"');
    }
}
