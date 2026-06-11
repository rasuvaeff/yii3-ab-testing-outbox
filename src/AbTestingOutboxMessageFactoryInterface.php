<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use Rasuvaeff\Yii3AbTesting\Assignment;

/**
 * Turns an {@see Assignment} (and a conversion goal) into a stable outbox
 * payload. Isolating extraction and serialization here keeps the trackers thin
 * and makes the payload format swappable.
 *
 * @api
 */
interface AbTestingOutboxMessageFactoryInterface
{
    public function exposure(Assignment $assignment): AbTestingOutboxPayload;

    public function conversion(Assignment $assignment, string $goal): AbTestingOutboxPayload;
}
