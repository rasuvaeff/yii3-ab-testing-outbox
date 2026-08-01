<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use Rasuvaeff\Yii3AbTesting\CanonicalEventSerializer;
use Rasuvaeff\Yii3AbTesting\ConversionEvent;
use Rasuvaeff\Yii3AbTesting\EventSerializer;
use Rasuvaeff\Yii3AbTesting\ExposureEvent;

/**
 * Serializes a core event into an outbox message.
 *
 * The payload is the canonical schema v2 row, produced by the core's own
 * serializer rather than assembled here. In v1 this class built its own array,
 * which is precisely how the two delivery paths drifted: the direct sink and
 * the outbox dropped different fields, and nothing noticed.
 *
 * `event_id` is written into the payload **and** handed to
 * `Outbox::record(id: …)` by the trackers, so the message id and the payload
 * field always agree. That matters because the ClickHouse exporter may fill the
 * event-id column from either, and a mismatch would split one event into two
 * rows that never deduplicate.
 *
 * @api
 */
final readonly class DefaultAbTestingOutboxMessageFactory implements AbTestingOutboxMessageFactoryInterface
{
    /**
     * Kept as a class constant because consumers branch on it during the
     * migration window; it mirrors {@see CanonicalEventSerializer::SCHEMA_VERSION}.
     */
    public const int PAYLOAD_VERSION = CanonicalEventSerializer::SCHEMA_VERSION;

    public function __construct(
        private AggregateIdStrategyInterface $aggregateIdStrategy = new PseudonymousAggregateIdStrategy(),
        private EventSerializer $serializer = new CanonicalEventSerializer(),
    ) {}

    #[\Override]
    public function exposure(ExposureEvent $event): AbTestingOutboxPayload
    {
        return new AbTestingOutboxPayload(
            type: AbTestingOutboxEventType::Exposure->value,
            payload: $this->encode($this->serializer->exposure($event)),
            aggregateId: $this->aggregateIdStrategy->exposure($event),
        );
    }

    #[\Override]
    public function conversion(ConversionEvent $event): AbTestingOutboxPayload
    {
        return new AbTestingOutboxPayload(
            type: AbTestingOutboxEventType::Conversion->value,
            payload: $this->encode($this->serializer->conversion($event)),
            aggregateId: $this->aggregateIdStrategy->conversion($event),
        );
    }

    /**
     * @param array<string, scalar|null> $row
     */
    private function encode(array $row): string
    {
        return json_encode($row, JSON_THROW_ON_ERROR);
    }
}
