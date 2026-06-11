<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

/**
 * Stable outbox message types for A/B testing events. Kept short and
 * transport-neutral so a generic exporter can route them.
 *
 * @api
 */
enum AbTestingOutboxEventType: string
{
    case Exposure = 'ab.exposure';
    case Conversion = 'ab.conversion';
}
