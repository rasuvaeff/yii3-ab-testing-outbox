<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use Rasuvaeff\Yii3AbTesting\ConversionEvent;
use Rasuvaeff\Yii3AbTesting\ExposureEvent;

/**
 * Turns a core event into a stable outbox payload. Isolating serialization here
 * keeps the trackers thin and lets an application swap the payload format
 * without touching them.
 *
 * @api
 */
interface AbTestingOutboxMessageFactoryInterface
{
    public function exposure(ExposureEvent $event): AbTestingOutboxPayload;

    public function conversion(ConversionEvent $event): AbTestingOutboxPayload;
}
