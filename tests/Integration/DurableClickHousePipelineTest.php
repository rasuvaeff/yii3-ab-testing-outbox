<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\Yii3AbTesting\AssignmentSource;
use Rasuvaeff\Yii3AbTesting\ConversionEvent;
use Rasuvaeff\Yii3AbTesting\DecisionReason;
use Rasuvaeff\Yii3AbTesting\ExposureEvent;
use Rasuvaeff\Yii3AbTestingClickHouse\AnalyticsSchemaV2;
use Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;
use Rasuvaeff\Yii3AbTestingOutbox\DefaultAbTestingOutboxMessageFactory;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3Outbox\Outbox;
use Rasuvaeff\Yii3Outbox\RetryPolicy;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultClickHouseWriterFactory;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Rasuvaeff\Yii3OutboxDb\Migration\M260611000000CreateOutboxTable;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * Clean-install proof of the durable path: tracker -> SQLite outbox storage ->
 * exporter -> live ClickHouse, against the canonical schema v2 owned by
 * `yii3-ab-testing-clickhouse`. The v1 DDL this package still ships is applied
 * too, so the legacy files stay valid SQL while the queue drains.
 */
#[Test]
#[CoversNothing]
final class DurableClickHousePipelineTest
{
    private ?ClickHouseClientFactory $clientFactory = null;

    private ?ConnectionInterface $db = null;

    #[BeforeTest]
    public function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST');
        if ($host === false || $host === '') {
            return;
        }

        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
        $this->db->open();
        (new M260611000000CreateOutboxTable())->up(new MigrationBuilder(
            db: $this->db,
            informer: new NullMigrationInformer(),
        ));

        $this->clientFactory = new ClickHouseClientFactory(new ClickHouseConfig(
            host: $host,
            port: (int) $this->env('CLICKHOUSE_PORT', '8123'),
            database: $this->env('CLICKHOUSE_DB', 'default'),
            username: $this->env('CLICKHOUSE_USER', 'default'),
            password: $this->env('CLICKHOUSE_PASSWORD', ''),
        ));

        $legacy = AbTestingClickHouseRoutes::legacyV1Map();
        $client = $this->clientFactory->create();
        foreach ([
            AnalyticsSchemaV2::EXPOSURES_TABLE,
            AnalyticsSchemaV2::CONVERSIONS_TABLE,
            'ab_exposures',
            'ab_conversions',
            $legacy['ab.exposure']['table'],
            $legacy['ab.conversion']['table'],
            '_migrations',
        ] as $table) {
            $client->executeQuery('DROP TABLE IF EXISTS ' . $table);
        }

        // The schema owner creates the v2 tables the routes target.
        (new SchemaMigrations(client: $client))->apply();

        // The v1 tables shipped here remain applicable for draining.
        (new ClickHouseMigrationRunner(
            client: $client,
            migrationsPath: dirname(__DIR__, 2) . '/migrations',
            placeholders: [
                'outbox_exposures_table' => $legacy['ab.exposure']['table'],
                'outbox_conversions_table' => $legacy['ab.conversion']['table'],
            ],
        ))->run();
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db?->close();
    }

    public function tracksThroughDbOutboxAndExportsToTheCanonicalSchema(): void
    {
        if (!$this->clientFactory instanceof ClickHouseClientFactory || !$this->db instanceof ConnectionInterface) {
            return;
        }

        $clock = new StaticClock(new DateTimeImmutable('2026-07-29 12:00:00', new DateTimeZone('UTC')));
        $occurredAt = new DateTimeImmutable('2026-07-29 10:00:00.123', new DateTimeZone('UTC'));
        $storage = new DbOutboxStorage(db: $this->db);
        $outbox = new Outbox(storage: $storage, clock: $clock);
        $messageFactory = new DefaultAbTestingOutboxMessageFactory();

        $exposureEvent = new ExposureEvent(
            eventId: 'exposure-1',
            occurredAt: $occurredAt,
            experiment: 'checkout-button',
            variant: 'green',
            subjectId: 'user-1',
            reason: DecisionReason::Assigned,
            source: AssignmentSource::Store,
            experimentRevision: 'db:7',
            environment: 'production',
            dimensions: ['country' => 'RU'],
        );
        $conversionEvent = new ConversionEvent(
            eventId: 'conversion-1',
            occurredAt: $occurredAt,
            experiment: 'checkout-button',
            variant: 'green',
            subjectId: 'user-1',
            goal: 'purchase',
            reason: DecisionReason::Assigned,
            source: AssignmentSource::Store,
            experimentRevision: 'db:7',
            environment: 'production',
            dimensions: ['country' => 'RU'],
            exposureEventId: 'exposure-1',
        );

        (new OutboxExposureTracker(outbox: $outbox, messageFactory: $messageFactory))->trackExposure($exposureEvent);
        (new OutboxConversionTracker(outbox: $outbox, messageFactory: $messageFactory))
            ->trackConversion($conversionEvent);

        Assert::count($storage->findPending(), 2);

        $result = (new ClickHouseOutboxExporter(
            storage: $storage,
            router: new MapClickHouseMessageRouter(routes: AbTestingClickHouseRoutes::map()),
            retryPolicy: new RetryPolicy(maxAttempts: 5, delaySeconds: 0),
            clock: $clock,
            writerFactory: new DefaultClickHouseWriterFactory(clientFactory: $this->clientFactory),
        ))->export();

        Assert::same($result->published, 2);
        Assert::same($result->retryScheduled, 0);
        Assert::same($result->terminalFailed, 0);
        Assert::same($storage->findPending(), []);

        $exposure = $this->readOne(
            table: AnalyticsSchemaV2::EXPOSURES_TABLE,
            columns: AbTestingClickHouseRoutes::map()['ab.exposure']['columns'],
        );
        $conversion = $this->readOne(
            table: AnalyticsSchemaV2::CONVERSIONS_TABLE,
            columns: AbTestingClickHouseRoutes::map()['ab.conversion']['columns'],
        );

        // The message id and the payload's event_id are the same value, so the
        // exporter filling the column from the message id cannot split the
        // event into two rows.
        Assert::same($exposure['event_id'], 'exposure-1');
        Assert::same($exposure['occurred_at'], '2026-07-29 10:00:00.123');
        Assert::same($exposure['experiment'], 'checkout-button');
        Assert::same($exposure['decision_reason'], 'assigned');
        Assert::same($exposure['assignment_source'], 'store');
        Assert::same($exposure['experiment_revision'], 'db:7');
        Assert::same($exposure['environment'], 'production');
        Assert::same($exposure['dimensions'], '{"country":"RU"}');

        Assert::same($conversion['event_id'], 'conversion-1');
        Assert::same($conversion['goal'], 'purchase');
        Assert::same($conversion['exposure_event_id'], 'exposure-1');
        Assert::same($conversion['dimensions'], '{"country":"RU"}');
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    /**
     * @param non-empty-string $table
     * @param non-empty-list<string> $columns
     *
     * @return array<string, mixed>
     */
    private function readOne(string $table, array $columns): array
    {
        return (new ClickHouseDataReader(
            client: $this->clientFactory->create(),
            table: $table,
            queryBuilder: ClickHouseQueryBuilder::create(allowedFields: $columns),
            mapper: static fn(array $row): array => $row,
            columns: $columns,
        ))->readOne();
    }
}
