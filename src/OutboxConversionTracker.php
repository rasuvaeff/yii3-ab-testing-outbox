<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use Rasuvaeff\Yii3AbTesting\ConversionEvent;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3Outbox\Outbox;

/**
 * Records each conversion as a durable outbox message via {@see Outbox::record()}.
 *
 * Deliberately NOT a `FlushableTracker` (see {@see OutboxExposureTracker}).
 *
 * @api
 */
final readonly class OutboxConversionTracker implements ConversionTracker
{
    public function __construct(
        private Outbox $outbox,
        private AbTestingOutboxMessageFactoryInterface $messageFactory = new DefaultAbTestingOutboxMessageFactory(),
    ) {}

    #[\Override]
    public function trackConversion(ConversionEvent $event): void
    {
        $message = $this->messageFactory->conversion($event);

        $this->outbox->record(
            type: $message->type,
            payload: $message->payload,
            aggregateId: $message->aggregateId,
            id: $event->eventId,
        );
    }
}
