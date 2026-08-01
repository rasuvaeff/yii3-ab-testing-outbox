<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use Rasuvaeff\Yii3AbTesting\ExposureEvent;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3Outbox\Outbox;

/**
 * Records each exposure as a durable outbox message via {@see Outbox::record()}.
 * The request path never touches the analytics sink — a worker exports the
 * outbox later.
 *
 * Deliberately NOT a `FlushableTracker`: the event is persisted immediately, so
 * there is no request-local buffer to flush (contrast `yii3-ab-testing-clickhouse`).
 *
 * @api
 */
final readonly class OutboxExposureTracker implements ExposureTracker
{
    public function __construct(
        private Outbox $outbox,
        private AbTestingOutboxMessageFactoryInterface $messageFactory = new DefaultAbTestingOutboxMessageFactory(),
    ) {}

    #[\Override]
    public function trackExposure(ExposureEvent $event): void
    {
        $message = $this->messageFactory->exposure($event);

        $this->outbox->record(
            type: $message->type,
            payload: $message->payload,
            aggregateId: $message->aggregateId,
            // The core already minted the identity, and it is inside the
            // payload too. Passing it here keeps the message id and the payload
            // field equal, so a retry produces one row rather than two that
            // never deduplicate.
            id: $event->eventId,
        );
    }
}
