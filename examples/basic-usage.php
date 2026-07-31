<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;
use Rasuvaeff\Yii3AbTestingOutbox\AllowListAnalyticsContextPolicy;
use Rasuvaeff\Yii3AbTestingOutbox\DefaultAbTestingOutboxMessageFactory;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\PseudonymousAggregateIdStrategy;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-11 12:00:00');
    }
};

$storage = new InMemoryStorage();
$outbox = new Outbox(storage: $storage, clock: $clock);

$messageFactory = new DefaultAbTestingOutboxMessageFactory(
    clock: $clock,
    aggregateIdStrategy: new PseudonymousAggregateIdStrategy(secret: 'example-secret-from-env'),
    contextPolicy: new AllowListAnalyticsContextPolicy(
        allowedAttributes: ['country', 'email'],
        renamedAttributes: ['country' => 'market'],
        redactedAttributes: ['email'],
    ),
);
$exposureTracker = new OutboxExposureTracker($outbox, $messageFactory);
$conversionTracker = new OutboxConversionTracker($outbox, $messageFactory);

$assignment = new Assignment(
    experiment: 'checkout',
    variant: 'green',
    subjectId: 'user-1',
    context: AssignmentContext::forEnvironment('production')
        ->withAttribute('country', 'DE')
        ->withAttribute('email', 'person@example.com')
        ->withAttribute('debug', true),
);

echo "1. Track exposure + conversion (durably recorded, no analytics call):\n";
$exposureTracker->trackExposure($assignment, eventId: 'exposure-order-42');
$conversionTracker->trackConversion($assignment, goal: 'purchase', eventId: 'conversion-order-42');

echo "2. Pending outbox messages:\n";
foreach ($storage->findPending() as $message) {
    echo "   {$message->getType()} [{$message->getAggregateId()}] -> {$message->getPayload()}\n";
}

echo "3. Route map for yii3-outbox-clickhouse:\n";
foreach (AbTestingClickHouseRoutes::map() as $type => $route) {
    echo "   {$type} -> {$route['table']} (" . implode(', ', $route['columns']) . ")\n";
}
