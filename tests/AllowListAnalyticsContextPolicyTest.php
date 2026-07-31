<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTestingOutbox\AllowListAnalyticsContextPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(AllowListAnalyticsContextPolicy::class)]
final class AllowListAnalyticsContextPolicyTest
{
    public function allowsRenamesRedactsAndDropsUnknownAttributes(): void
    {
        $policy = new AllowListAnalyticsContextPolicy(
            allowedAttributes: ['country', 'plan', 'email'],
            renamedAttributes: ['plan' => 'billing_plan'],
            redactedAttributes: ['email'],
        );
        $context = new AssignmentContext(attributes: [
            'country' => 'DE',
            'plan' => 'pro',
            'email' => 'person@example.com',
            'debug' => true,
        ]);

        Assert::same($policy->apply($context), [
            'country' => 'DE',
            'billing_plan' => 'pro',
            'email' => AllowListAnalyticsContextPolicy::REDACTED,
        ]);
    }

    public function nullContextAndEmptyAllowListProduceNoDimensions(): void
    {
        $policy = new AllowListAnalyticsContextPolicy();

        Assert::same($policy->apply(null), []);
        Assert::same($policy->apply(new AssignmentContext(attributes: ['country' => 'DE'])), []);
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function rejectsInvalidConfiguration(array $allowed, array $renamed, array $redacted): void
    {
        Expect::exception(InvalidArgumentException::class);

        new AllowListAnalyticsContextPolicy(
            allowedAttributes: $allowed,
            renamedAttributes: $renamed,
            redactedAttributes: $redacted,
        );
    }

    /**
     * @return iterable<string, array{list<string>, array<string, string>, list<string>}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'invalid source identifier' => [['bad-name'], [], []];
        yield 'invalid destination identifier' => [['country'], ['country' => 'bad-name'], []];
        yield 'rename outside allow-list' => [['country'], ['plan' => 'billing_plan'], []];
        yield 'redaction outside allow-list' => [['country'], [], ['email']];
        yield 'duplicate destination' => [['country', 'market'], ['country' => 'market'], []];
    }
}
