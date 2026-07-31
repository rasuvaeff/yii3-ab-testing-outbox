<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use Rasuvaeff\Yii3AbTesting\AssignmentContext;

/**
 * Selects the context attributes safe to persist in the extensible v1 payload.
 *
 * @api
 */
interface AnalyticsContextPolicyInterface
{
    /**
     * @return array<string, scalar>
     */
    public function apply(?AssignmentContext $context): array;
}
