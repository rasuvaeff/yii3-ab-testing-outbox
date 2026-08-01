<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Rasuvaeff\Yii3AbTestingOutbox\PseudonymousAggregateIdStrategy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(PseudonymousAggregateIdStrategy::class)]
final class PseudonymousAggregateIdStrategyTest
{
    public function idsAreStableAndDoNotExposeTheirInputs(): void
    {
        $strategy = new PseudonymousAggregateIdStrategy(secret: 'application-secret');
        $event = Events::exposure(experiment: 'checkout', subjectId: 'user@example.com');

        $first = $strategy->exposure($event);
        $second = $strategy->exposure($event);

        Assert::same($first, $second);
        Assert::same(strlen($first), 73);
        Assert::false(str_contains($first, 'checkout'));
        Assert::false(str_contains($first, 'user@example.com'));
    }

    public function eventKindsAndConversionGoalsUseSeparateIds(): void
    {
        $strategy = new PseudonymousAggregateIdStrategy();

        Assert::false($strategy->exposure(Events::exposure()) === $strategy->conversion(Events::conversion()));
        Assert::false(
            $strategy->conversion(Events::conversion(goal: 'purchase'))
            === $strategy->conversion(Events::conversion(goal: 'signup')),
        );
    }

    public function idsCarryTheEventKindAsALeadingPrefix(): void
    {
        $strategy = new PseudonymousAggregateIdStrategy();

        Assert::same(substr($strategy->exposure(Events::exposure()), 0, 9), 'exposure:');
        Assert::same(substr($strategy->conversion(Events::conversion()), 0, 11), 'conversion:');
    }

    public function differentExperimentsNeverShareAnId(): void
    {
        $strategy = new PseudonymousAggregateIdStrategy();

        Assert::false(
            $strategy->exposure(Events::exposure(experiment: 'checkout'))
            === $strategy->exposure(Events::exposure(experiment: 'pricing')),
        );
        Assert::false(
            $strategy->conversion(Events::conversion(experiment: 'checkout'))
            === $strategy->conversion(Events::conversion(experiment: 'pricing')),
        );
    }

    /**
     * The aggregate id groups a subject's events for diagnostics, so it must
     * not vary with the identity or the time of the individual event — those
     * belong to `event_id` and `occurred_at`.
     */
    public function idsIgnoreEventIdentityAndTime(): void
    {
        $strategy = new PseudonymousAggregateIdStrategy();

        Assert::same(
            $strategy->exposure(Events::exposure(eventId: 'evt-1')),
            $strategy->exposure(Events::exposure(
                eventId: 'evt-2',
                occurredAt: new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
            )),
        );
    }
}
