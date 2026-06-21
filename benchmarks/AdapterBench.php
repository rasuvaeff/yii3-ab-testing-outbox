<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Benchmarks;

use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingOutboxPayload;
use Rasuvaeff\Yii3AbTestingOutbox\DefaultAbTestingOutboxMessageFactory;
use Rasuvaeff\Yii3AbTestingOutbox\SystemClock;
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
        $factory = new DefaultAbTestingOutboxMessageFactory(clock: new SystemClock());
        $assignment = new Assignment(
            experiment: 'checkout-button',
            variant: 'treatment',
            subjectId: 'user-42',
        );

        return $factory->exposure(assignment: $assignment);
    }

    public static function buildConversion(): AbTestingOutboxPayload
    {
        $factory = new DefaultAbTestingOutboxMessageFactory(clock: new SystemClock());
        $assignment = new Assignment(
            experiment: 'checkout-button',
            variant: 'treatment',
            subjectId: 'user-42',
        );

        return $factory->conversion(assignment: $assignment, goal: 'purchase');
    }
}
