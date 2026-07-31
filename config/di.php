<?php

declare(strict_types=1);

use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingOutboxMessageFactoryInterface;
use Rasuvaeff\Yii3AbTestingOutbox\AllowListAnalyticsContextPolicy;
use Rasuvaeff\Yii3AbTestingOutbox\DefaultAbTestingOutboxMessageFactory;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\PseudonymousAggregateIdStrategy;
use Rasuvaeff\Yii3Outbox\Outbox;

/** @var array $params */

return [
    AbTestingOutboxMessageFactoryInterface::class => static function () use ($params): AbTestingOutboxMessageFactoryInterface {
        $config = $params['rasuvaeff/yii3-ab-testing-outbox'] ?? [];
        $context = $config['context'] ?? [];

        return new DefaultAbTestingOutboxMessageFactory(
            aggregateIdStrategy: new PseudonymousAggregateIdStrategy(
                secret: (string) ($config['aggregateIdSecret'] ?? ''),
            ),
            contextPolicy: new AllowListAnalyticsContextPolicy(
                allowedAttributes: $context['allowedAttributes'] ?? [],
                renamedAttributes: $context['renamedAttributes'] ?? [],
                redactedAttributes: $context['redactedAttributes'] ?? [],
            ),
        );
    },
    ExposureTracker::class => static fn (
        Outbox $outbox,
        AbTestingOutboxMessageFactoryInterface $messageFactory,
    ): ExposureTracker => new OutboxExposureTracker($outbox, $messageFactory),
    ConversionTracker::class => static fn (
        Outbox $outbox,
        AbTestingOutboxMessageFactoryInterface $messageFactory,
    ): ConversionTracker => new OutboxConversionTracker($outbox, $messageFactory),
];
