<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Benchmarks;

use DateTimeImmutable;
use DateTimeZone;
use Rasuvaeff\Yii3AbTesting\AssignmentSource;
use Rasuvaeff\Yii3AbTesting\ConversionEvent;
use Rasuvaeff\Yii3AbTesting\DecisionReason;
use Rasuvaeff\Yii3AbTesting\ExposureEvent;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingOutboxPayload;
use Rasuvaeff\Yii3AbTestingOutbox\DefaultAbTestingOutboxMessageFactory;
use Testo\Bench;

final class AdapterBench
{
    #[Bench(
        callables: [
            'conversion' => [self::class, 'buildConversion'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function buildExposure(): AbTestingOutboxPayload
    {
        return (new DefaultAbTestingOutboxMessageFactory())->exposure(new ExposureEvent(
            eventId: 'exposure-1',
            occurredAt: self::time(),
            experiment: 'checkout-button',
            variant: 'treatment',
            subjectId: 'user-42',
            reason: DecisionReason::Assigned,
            source: AssignmentSource::Computed,
        ));
    }

    public static function buildConversion(): AbTestingOutboxPayload
    {
        return (new DefaultAbTestingOutboxMessageFactory())->conversion(new ConversionEvent(
            eventId: 'conversion-1',
            occurredAt: self::time(),
            experiment: 'checkout-button',
            variant: 'treatment',
            subjectId: 'user-42',
            goal: 'purchase',
            reason: DecisionReason::Assigned,
            source: AssignmentSource::Computed,
        ));
    }

    private static function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-01 10:00:00.123', new DateTimeZone('UTC'));
    }
}
