<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use Rasuvaeff\Yii3AbTesting\Assignment;

/**
 * Builds diagnostic outbox aggregate ids independently from analytics payloads.
 *
 * @api
 */
interface AggregateIdStrategyInterface
{
    public function exposure(Assignment $assignment): ?string;

    public function conversion(Assignment $assignment, string $goal): ?string;
}
