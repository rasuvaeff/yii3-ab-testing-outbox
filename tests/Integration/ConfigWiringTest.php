<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3AbTesting\ConversionTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3Outbox\Outbox;

/**
 * Exercises the package `config/di.php`, covered by neither cs, psalm nor the
 * unit suite. The producer binds exactly the swappable tracker keys; the core
 * `yii3-ab-testing` does not bind them, so this package (or the app) is the
 * single source — yiisoft/config rejects duplicate keys across vendor packages.
 */
#[CoversNothing]
final class ConfigWiringTest extends TestCase
{
    #[Test]
    public function bindsOnlyTheTrackerKeys(): void
    {
        $this->assertSame(
            [ExposureTracker::class, ConversionTracker::class],
            array_keys($this->loadDi()),
        );
    }

    #[Test]
    public function exposureFactoryBuildsOutboxTracker(): void
    {
        $factory = $this->loadDi()[ExposureTracker::class];
        $this->assertIsCallable($factory);

        $this->assertInstanceOf(OutboxExposureTracker::class, $factory($this->outbox()));
    }

    #[Test]
    public function conversionFactoryBuildsOutboxTracker(): void
    {
        $factory = $this->loadDi()[ConversionTracker::class];
        $this->assertIsCallable($factory);

        $this->assertInstanceOf(OutboxConversionTracker::class, $factory($this->outbox()));
    }

    #[Test]
    public function coreAndProducerDoNotShareDiKeys(): void
    {
        $overlap = array_intersect_key($this->loadCore(), $this->loadDi());

        $this->assertSame(
            [],
            $overlap,
            'core and -outbox must not define the same di key (yiisoft/config Duplicate key)',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDi(): array
    {
        return require dirname(__DIR__, 2) . '/config/di.php';
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
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-06-11 12:00:00'));

        return new Outbox(storage: new InMemoryStorage(), clock: $clock);
    }
}
