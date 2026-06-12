<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Wall-clock default for {@see DefaultAbTestingOutboxMessageFactory}. Inject your
 * own {@see ClockInterface} (e.g. in tests) to control the event time.
 *
 * @internal
 */
final class SystemClock implements ClockInterface
{
    #[\Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
