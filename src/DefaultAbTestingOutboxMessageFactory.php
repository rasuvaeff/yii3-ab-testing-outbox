<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3AbTesting\Assignment;

/**
 * Default JSON factory. Boolean flags are serialized as `0|1` (ClickHouse
 * `UInt8`-friendly), `environment` is always present (empty string when no
 * context), and `event_at` carries the event time (UTC `Y-m-d H:i:s`) from the
 * injected clock — the moment the event was tracked, not when a worker later
 * exports it. A transport-meta `v` field carries {@see PAYLOAD_VERSION} so
 * downstream consumers can distinguish schema generations without parsing every
 * field. Payload field names match the analytics columns owned by
 * `yii3-ab-testing-clickhouse` (see {@see AbTestingClickHouseRoutes}).
 *
 * @api
 */
final readonly class DefaultAbTestingOutboxMessageFactory implements AbTestingOutboxMessageFactoryInterface
{
    public const int PAYLOAD_VERSION = 1;

    private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private ClockInterface $clock = new SystemClock(),
    ) {}

    #[\Override]
    public function exposure(Assignment $assignment): AbTestingOutboxPayload
    {
        return new AbTestingOutboxPayload(
            type: AbTestingOutboxEventType::Exposure->value,
            payload: $this->encode($this->baseFields($assignment)),
            aggregateId: $assignment->experiment . ':' . $assignment->subjectId,
        );
    }

    #[\Override]
    public function conversion(Assignment $assignment, string $goal): AbTestingOutboxPayload
    {
        if ($goal === '') {
            throw new InvalidArgumentException('Conversion goal must not be empty');
        }

        $fields = $this->baseFields($assignment);
        $fields['goal'] = $goal;

        return new AbTestingOutboxPayload(
            type: AbTestingOutboxEventType::Conversion->value,
            payload: $this->encode($fields),
            aggregateId: $assignment->experiment . ':' . $assignment->subjectId . ':' . $goal,
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function baseFields(Assignment $assignment): array
    {
        return [
            'v' => self::PAYLOAD_VERSION,
            'event_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME_FORMAT),
            'experiment' => $assignment->experiment,
            'variant' => $assignment->variant,
            'subject_id' => $assignment->subjectId,
            'is_forced' => (int) $assignment->isForced,
            'is_fallback' => (int) $assignment->isFallback,
            'is_sticky' => (int) $assignment->isSticky,
            'environment' => $assignment->context?->getEnvironment() ?? '',
        ];
    }

    /**
     * @param array<string, int|string> $fields
     */
    private function encode(array $fields): string
    {
        return json_encode($fields, JSON_THROW_ON_ERROR);
    }
}
