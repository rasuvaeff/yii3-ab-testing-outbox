<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use InvalidArgumentException;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;

/**
 * Explicitly allows context attributes, optionally renames output keys, and
 * replaces selected values with a fixed marker before persistence.
 *
 * @api
 */
final readonly class AllowListAnalyticsContextPolicy implements AnalyticsContextPolicyInterface
{
    public const string REDACTED = '[redacted]';

    /**
     * @param list<string> $allowedAttributes
     * @param array<string, string> $renamedAttributes input name => payload name
     * @param list<string> $redactedAttributes
     */
    public function __construct(
        private array $allowedAttributes = [],
        private array $renamedAttributes = [],
        private array $redactedAttributes = [],
    ) {
        $destinations = [];

        foreach ($allowedAttributes as $attribute) {
            $this->assertIdentifier($attribute);
            $destination = $renamedAttributes[$attribute] ?? $attribute;
            $this->assertIdentifier($destination);

            if ($destinations[$destination] ?? false) {
                throw new InvalidArgumentException(sprintf('Duplicate analytics context field "%s"', $destination));
            }

            $destinations[$destination] = true;
        }

        foreach (array_keys($renamedAttributes) as $attribute) {
            if (!\in_array($attribute, $allowedAttributes, true)) {
                throw new InvalidArgumentException(sprintf('Renamed attribute "%s" must be allow-listed', $attribute));
            }
        }

        foreach ($redactedAttributes as $attribute) {
            if (!\in_array($attribute, $allowedAttributes, true)) {
                throw new InvalidArgumentException(sprintf('Redacted attribute "%s" must be allow-listed', $attribute));
            }
        }
    }

    #[\Override]
    public function apply(?AssignmentContext $context): array
    {
        if (!$context instanceof AssignmentContext) {
            return [];
        }

        $attributes = $context->getAttributes();
        $result = [];

        foreach ($this->allowedAttributes as $attribute) {
            if (!\array_key_exists($attribute, $attributes)) {
                continue;
            }

            $name = $this->renamedAttributes[$attribute] ?? $attribute;
            $result[$name] = \in_array($attribute, $this->redactedAttributes, true)
                ? self::REDACTED
                : $attributes[$attribute];
        }

        return $result;
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_]\w*\z/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid analytics context field "%s"', $identifier));
        }
    }
}
