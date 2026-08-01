<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\AllowListAnalyticsContextPolicy;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTesting\ConfigExperimentProvider;
use Rasuvaeff\Yii3AbTesting\SystemClock;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;

/**
 * The durable delivery path: the request writes a row to the outbox and
 * returns. A worker exports it to ClickHouse later, so an analytics outage
 * cannot touch the user's request.
 *
 * In-memory storage keeps this runnable; production uses `yii3-outbox-db`.
 */
$storage = new InMemoryStorage();
$outbox = new Outbox(storage: $storage, clock: new SystemClock());

$ab = new AbTesting(
    provider: new ConfigExperimentProvider(config: [
        'checkout' => [
            'enabled' => true,
            'salt' => 'checkout-v1',
            'fallbackVariant' => 'control',
            'variants' => ['control' => 50, 'green' => 50],
        ],
    ]),
    strategy: new WeightedHashAssignmentStrategy(),
    exposureTracker: new OutboxExposureTracker($outbox),
    conversionTracker: new OutboxConversionTracker($outbox),
    // The allow-list lives on the facade, so every delivery path filters
    // identically. Configured per-adapter it would have covered this one only.
    contextPolicy: new AllowListAnalyticsContextPolicy(allowedAttributes: ['country']),
);

$context = AssignmentContext::forEnvironment('production')
    ->withAttribute('country', 'RU')
    ->withAttribute('email', 'never@leaves.local');

$assignment = $ab->assign(experiment: 'checkout', subjectId: 'user-1', context: $context);
$exposure = $ab->trackExposure($assignment);
$ab->trackConversion($assignment, goal: 'purchase', exposure: $exposure);

echo "Queued outbox messages:\n\n";

foreach ($storage->findPending() as $message) {
    echo sprintf("  %s\n", $message->getType());
    echo sprintf("    id:        %s\n", $message->getId());
    echo sprintf("    aggregate: %s\n", (string) $message->getAggregateId());
    echo sprintf("    payload:   %s\n\n", $message->getPayload());
}

echo "Note three things in the payload:\n";
echo "  - `event_id` equals the message id, so a retry of the same domain\n";
echo "    event stays one row rather than two that never deduplicate;\n";
echo "  - `email` is absent — the allow-list dropped it;\n";
echo "  - `dimensions` is a JSON string, because the exporter refuses nested\n";
echo "    payload fields.\n";

echo "\nThe worker routes them with:\n";
echo "  new MapClickHouseMessageRouter(routes: AbTestingClickHouseRoutes::map());\n";
echo "  target tables: " . implode(', ', array_column(AbTestingClickHouseRoutes::map(), 'table')) . "\n";
echo "\nWhile messages queued before the 2.0 upgrade are still pending, keep\n";
echo "AbTestingClickHouseRoutes::legacyV1Map() wired too — a v1 payload has no\n";
echo "decision_reason and would fail against the v2 tables.\n";
