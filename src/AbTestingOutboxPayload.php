<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

/**
 * Transport description produced by the message factory before it is handed to
 * {@see \Rasuvaeff\Yii3Outbox\Outbox::record()}: the message type, the JSON
 * payload, and an optional aggregate id.
 *
 * @api
 */
final readonly class AbTestingOutboxPayload
{
    public function __construct(
        public string $type,
        public string $payload,
        public ?string $aggregateId,
    ) {}
}
