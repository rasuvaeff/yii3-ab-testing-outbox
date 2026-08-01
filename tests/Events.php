<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Rasuvaeff\Yii3AbTesting\AssignmentSource;
use Rasuvaeff\Yii3AbTesting\ConversionEvent;
use Rasuvaeff\Yii3AbTesting\DecisionReason;
use Rasuvaeff\Yii3AbTesting\ExposureEvent;

/**
 * Event builders with defaults, so a test states only the field it is about.
 */
final class Events
{
    /**
     * @param array<string, scalar> $dimensions
     */
    public static function exposure(
        string $eventId = 'evt-1',
        string $experiment = 'checkout',
        string $variant = 'green',
        string $subjectId = 'user-1',
        DecisionReason $reason = DecisionReason::Assigned,
        AssignmentSource $source = AssignmentSource::Computed,
        array $dimensions = [],
        ?DateTimeImmutable $occurredAt = null,
        ?string $experimentRevision = null,
        string $environment = '',
    ): ExposureEvent {
        return new ExposureEvent(
            eventId: $eventId,
            occurredAt: $occurredAt ?? self::time(),
            experiment: $experiment,
            variant: $variant,
            subjectId: $subjectId,
            reason: $reason,
            source: $source,
            experimentRevision: $experimentRevision,
            environment: $environment,
            dimensions: $dimensions,
        );
    }

    /**
     * @param array<string, scalar> $dimensions
     */
    public static function conversion(
        string $eventId = 'evt-2',
        string $experiment = 'checkout',
        string $variant = 'green',
        string $subjectId = 'user-1',
        string $goal = 'purchase',
        DecisionReason $reason = DecisionReason::Assigned,
        AssignmentSource $source = AssignmentSource::Computed,
        array $dimensions = [],
        ?DateTimeImmutable $occurredAt = null,
        ?string $experimentRevision = null,
        string $environment = '',
        ?string $exposureEventId = null,
    ): ConversionEvent {
        return new ConversionEvent(
            eventId: $eventId,
            occurredAt: $occurredAt ?? self::time(),
            experiment: $experiment,
            variant: $variant,
            subjectId: $subjectId,
            goal: $goal,
            reason: $reason,
            source: $source,
            experimentRevision: $experimentRevision,
            environment: $environment,
            dimensions: $dimensions,
            exposureEventId: $exposureEventId,
        );
    }

    public static function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-01 10:00:00.123', new DateTimeZone('UTC'));
    }
}
