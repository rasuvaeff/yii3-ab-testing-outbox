<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use Rasuvaeff\Yii3AbTesting\Assignment;

/**
 * Deterministic aggregate ids that never contain the raw subject id. Inject an
 * application secret to make offline guessing impractical.
 *
 * @api
 */
final readonly class PseudonymousAggregateIdStrategy implements AggregateIdStrategyInterface
{
    public function __construct(
        private string $secret = '',
    ) {}

    #[\Override]
    public function exposure(Assignment $assignment): string
    {
        return 'exposure:' . $this->digest([
            $assignment->experiment,
            $assignment->subjectId,
        ]);
    }

    #[\Override]
    public function conversion(Assignment $assignment, string $goal): string
    {
        return 'conversion:' . $this->digest([
            $assignment->experiment,
            $assignment->subjectId,
            $goal,
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
