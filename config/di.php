<?php

declare(strict_types=1);

use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3Outbox\Outbox;

return [
    ExposureTracker::class => static fn (Outbox $outbox): ExposureTracker => new OutboxExposureTracker($outbox),
    ConversionTracker::class => static fn (Outbox $outbox): ConversionTracker => new OutboxConversionTracker($outbox),
];
