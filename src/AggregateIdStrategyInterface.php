<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use Rasuvaeff\Yii3AbTesting\ConversionEvent;
use Rasuvaeff\Yii3AbTesting\ExposureEvent;

/**
 * Builds diagnostic outbox aggregate ids independently from analytics payloads.
 *
 * @api
 */
interface AggregateIdStrategyInterface
{
    public function exposure(ExposureEvent $event): ?string;

    public function conversion(ConversionEvent $event): ?string;
}
