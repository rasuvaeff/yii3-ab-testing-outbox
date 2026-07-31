<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use Rasuvaeff\Yii3AbTesting\Assignment;
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
        $assignment = new Assignment(experiment: 'checkout', variant: 'a', subjectId: 'user@example.com');

        $first = $strategy->exposure($assignment);
        $second = $strategy->exposure($assignment);

        Assert::same($first, $second);
        Assert::same(strlen($first), 73);
        Assert::false(str_contains($first, 'checkout'));
        Assert::false(str_contains($first, 'user@example.com'));
    }

    public function eventKindsAndConversionGoalsUseSeparateIds(): void
    {
        $strategy = new PseudonymousAggregateIdStrategy();
        $assignment = new Assignment(experiment: 'checkout', variant: 'a', subjectId: 'user-1');

        Assert::false($strategy->exposure($assignment) === $strategy->conversion($assignment, 'purchase'));
        Assert::false($strategy->conversion($assignment, 'purchase') === $strategy->conversion($assignment, 'signup'));
    }
}
