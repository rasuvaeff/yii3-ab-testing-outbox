<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingOutbox\Tests\Integration;

use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\Yii3AbTesting\Assignment;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;
use Rasuvaeff\Yii3AbTestingOutbox\DefaultAbTestingOutboxMessageFactory;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\Tests\FakeClock;
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
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

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

        $client = $this->clientFactory->create();
        foreach ([AbTestingClickHouseRoutes::EXPOSURES_TABLE, AbTestingClickHouseRoutes::CONVERSIONS_TABLE, '_migrations'] as $table) {
            $client->executeQuery('DROP TABLE IF EXISTS ' . $table);
        }

        (new ClickHouseMigrationRunner(
            client: $client,
            migrationsPath: dirname(__DIR__, 2) . '/migrations',
            placeholders: [
                'outbox_exposures_table' => AbTestingClickHouseRoutes::EXPOSURES_TABLE,
                'outbox_conversions_table' => AbTestingClickHouseRoutes::CONVERSIONS_TABLE,
            ],
        ))->run();
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db?->close();
    }

    public function tracksThroughDbOutboxAndExportsToShippedClickHouseSchema(): void
    {
        if (!$this->clientFactory instanceof \Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory || !$this->db instanceof \Yiisoft\Db\Connection\ConnectionInterface) {
            return;
        }

        $clock = new FakeClock(new \DateTimeImmutable('2026-07-29 10:00:00', new \DateTimeZone('UTC')));
        $storage = new DbOutboxStorage(db: $this->db);
        $outbox = new Outbox(storage: $storage, clock: $clock);
        $messageFactory = new DefaultAbTestingOutboxMessageFactory(clock: $clock);
        $assignment = new Assignment(
            experiment: 'checkout-button',
            variant: 'green',
            subjectId: 'user-1',
            context: AssignmentContext::forEnvironment('production'),
            isSticky: true,
        );

        (new OutboxExposureTracker(outbox: $outbox, messageFactory: $messageFactory))->trackExposure($assignment);
        (new OutboxConversionTracker(outbox: $outbox, messageFactory: $messageFactory))->trackConversion(
            assignment: $assignment,
            goal: 'purchase',
        );

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
            table: AbTestingClickHouseRoutes::EXPOSURES_TABLE,
            columns: AbTestingClickHouseRoutes::map()['ab.exposure']['columns'],
        );
        $conversion = $this->readOne(
            table: AbTestingClickHouseRoutes::CONVERSIONS_TABLE,
            columns: AbTestingClickHouseRoutes::map()['ab.conversion']['columns'],
        );

        Assert::true(is_string($exposure['event_id']) && $exposure['event_id'] !== '');
        Assert::same($exposure['event_at'], '2026-07-29 10:00:00');
        Assert::same($exposure['experiment'], 'checkout-button');
        Assert::same($exposure['is_sticky'], 1);
        Assert::same($exposure['environment'], 'production');
        Assert::same($conversion['goal'], 'purchase');
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
