<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests\Integration;

use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingOutboxMessageFactoryInterface;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * Exercises the package `config/di.php`, covered by neither cs, psalm nor the
 * unit suite. The producer binds exactly the swappable tracker keys; the core
 * `yii3-ab-testing` does not bind them, so this package (or the app) is the
 * single source — yiisoft/config rejects duplicate keys across vendor packages.
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function bindsFactoryAndTrackerKeys(): void
    {
        Assert::same(
            array_keys($this->loadDi()),
            [AbTestingOutboxMessageFactoryInterface::class, ExposureTracker::class, ConversionTracker::class],
        );
    }

    public function exposureFactoryBuildsOutboxTracker(): void
    {
        $factory = $this->loadDi()[ExposureTracker::class];
        Assert::true(is_callable($factory));

        Assert::instanceOf($factory($this->outbox(), $this->messageFactory()), OutboxExposureTracker::class);
    }

    public function conversionFactoryBuildsOutboxTracker(): void
    {
        $factory = $this->loadDi()[ConversionTracker::class];
        Assert::true(is_callable($factory));

        Assert::instanceOf($factory($this->outbox(), $this->messageFactory()), OutboxConversionTracker::class);
    }

    public function coreAndProducerDoNotShareDiKeys(): void
    {
        $overlap = array_intersect_key($this->loadCore(), $this->loadDi());

        Assert::same($overlap, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDi(): array
    {
        return (static function (): array {
            $params = require dirname(__DIR__, 2) . '/config/params.php';

            return require dirname(__DIR__, 2) . '/config/di.php';
        })();
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCore(): array
    {
        $file = dirname(__DIR__, 2) . '/vendor/rasuvaeff/yii3-ab-testing/config/di.php';

        if (!is_file($file)) {
            return [];
        }

        $params = ['rasuvaeff/yii3-ab-testing' => ['experiments' => []]];

        return require $file;
    }

    private function outbox(): Outbox
    {
        return new Outbox(storage: new InMemoryStorage(), clock: new StaticClock(new \DateTimeImmutable('2026-06-11 12:00:00')));
    }

    private function messageFactory(): AbTestingOutboxMessageFactoryInterface
    {
        $factory = $this->loadDi()[AbTestingOutboxMessageFactoryInterface::class];

        return $factory();
    }
}
