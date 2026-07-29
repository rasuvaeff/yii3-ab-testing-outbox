<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use Psr\Clock\ClockInterface;

/**
 * @internal
 */
final readonly class FakeClock implements ClockInterface
{
    public function __construct(
        private \DateTimeImmutable $now,
    ) {}

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
