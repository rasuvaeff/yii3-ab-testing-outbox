<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use Rasuvaeff\Yii3AbTesting\Assignment;
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
    public function trackExposure(Assignment $assignment, ?string $eventId = null): void
    {
        $message = $this->messageFactory->exposure($assignment);

        $this->outbox->record(
            type: $message->type,
            payload: $message->payload,
            aggregateId: $message->aggregateId,
            id: $eventId,
        );
    }
}
