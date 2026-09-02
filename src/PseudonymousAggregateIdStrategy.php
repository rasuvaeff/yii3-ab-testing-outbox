<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use InvalidArgumentException;
use Rasuvaeff\Yii3AbTesting\ConversionEvent;
use Rasuvaeff\Yii3AbTesting\ExposureEvent;

/**
 * Deterministic aggregate ids that never contain the raw subject id.
 *
 * @api
 */
final readonly class PseudonymousAggregateIdStrategy implements AggregateIdStrategyInterface
{
    /**
     * @param non-empty-string $secret
     */
    public function __construct(
        private string $secret,
    ) {
        if (trim($secret) === '') {

            throw new InvalidArgumentException('Aggregate id secret must be non-empty');
        }
    }

    #[\Override]
    public function exposure(ExposureEvent $event): string
    {
        return 'exposure:' . $this->digest([
            $event->experiment,
            $event->subjectId,
        ]);
    }

    #[\Override]
    public function conversion(ConversionEvent $event): string
    {
        return 'conversion:' . $this->digest([
            $event->experiment,
            $event->subjectId,
            $event->goal,
        ]);
    }

    /**
     * @param list<string> $parts
     */
    private function digest(array $parts): string
    {
        return hash_hmac('sha256', implode("\0", $parts), $this->secret);
    }
}
