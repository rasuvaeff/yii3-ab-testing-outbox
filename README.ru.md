# rasuvaeff/yii3-ab-testing-outbox

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-ab-testing-outbox/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-outbox)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-ab-testing-outbox/downloads)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-outbox)
[![Build](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-ab-testing-outbox/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-ab-testing-outbox)
[![License](https://poser.pugx.org/rasuvaeff/yii3-ab-testing-outbox/license)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-outbox)
[English version](README.md)

Записывает события показов и конверсий из
[`rasuvaeff/yii3-ab-testing`](https://github.com/rasuvaeff/yii3-ab-testing) в
[`rasuvaeff/yii3-outbox`](https://github.com/rasuvaeff/yii3-outbox) как
долговечные сообщения. Путь запроса остаётся быстрым и переживает сбои
аналитики; воркер асинхронно выгружает outbox (например, через
`yii3-outbox-clickhouse`).

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник,
> которым можно поделиться с моделью.

## Прямой sink vs долговечный pipeline

| | Прямой | Долговечный (этот пакет) |
|---|---|---|
| Пакет | `yii3-ab-testing-clickhouse` | `yii3-ab-testing-outbox` + `yii3-outbox(-db)` + `yii3-outbox-clickhouse` |
| Батчинг | на запрос | крупный, меж-запросный |
| Переживает аварию ClickHouse | нет | да |
| Настройка | минимальная | воркер + outbox-хранилище |

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^1.2
- `rasuvaeff/yii3-outbox` ^1.0
- `psr/clock` ^1.0

## Установка

```bash
composer require rasuvaeff/yii3-ab-testing-outbox
```

Для полного долговечного пути в ClickHouse установите также DB-хранилище и
экспортёр, на которых работает проверенный pipeline:

```bash
composer require rasuvaeff/yii3-outbox-db rasuvaeff/yii3-outbox-clickhouse
```

## Использование

```php
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3Outbox\Outbox;

$outbox = new Outbox(storage: $storage, clock: $clock);   // storage from yii3-outbox-db
$exposureTracker = new OutboxExposureTracker($outbox);
$conversionTracker = new OutboxConversionTracker($outbox);

$assignment = $abTesting->assign(experiment: 'checkout', subjectId: $userId);
$exposureTracker->trackExposure($assignment, eventId: 'exposure-order-42');
// later, on the goal:
$conversionTracker->trackConversion($assignment, goal: 'purchase', eventId: 'conversion-order-42');
```

### Payload

Сообщения `ab.exposure` / `ab.conversion` несут JSON-объект, имена полей
которого совпадают с аналитическими колонками `yii3-ab-testing-clickhouse`:

```json
{"v":1,"event_at":"2026-06-12 10:00:00","experiment":"checkout","variant":"green","subject_id":"user-1","is_forced":0,"is_fallback":0,"is_sticky":0,"environment":"production","context":{}}
```

Ведущее поле `v` — это версия схемы transport-meta
(`DefaultAbTestingOutboxMessageFactory::PAYLOAD_VERSION`). Она **не** указана в
колонках `AbTestingClickHouseRoutes` и никогда не пишется в ClickHouse — она
существует, чтобы downstream-консьюмеры, читающие сырые outbox-сообщения, могли
различать поколения схемы payload'а. Для конверсий добавляется `"goal"`. Флаги
имеют значения `0|1`; `environment` присутствует всегда. `event_at` — время
события (UTC `Y-m-d H:i:s`), фиксируемое при трекинге; оно отличается от времени
экспорта воркером.

`context` — расширяемое поле v1 для дополнительных аналитических dimensions. По
умолчанию политика не сохраняет ни одного атрибута. Настройте
`AllowListAnalyticsContextPolicy`, чтобы явно разрешить атрибуты, переименовать
выходные ключи или заменить выбранные значения на `[redacted]`. Неизвестные
атрибуты всегда отбрасываются. Текущие `AbTestingClickHouseRoutes` намеренно не
содержат `context`, поэтому dimensions не меняют существующие ClickHouse v1
таблицы.

Consumer обязан ветвиться по `v` и в период rollout принимать все заявленные
версии. Producer v2 должен использовать новые event types/routes либо consumer,
который одновременно читает v1 и v2; переопределять смысл существующего поля v1
нельзя. Сохраняйте v1, пока все consumer'ы не понимают v2, используйте dual-read
на время миграции и отключайте v1 явно.

### Идентичность и повторы

Стандартный `PseudonymousAggregateIdStrategy` выдаёт стабильные HMAC-SHA-256 id
вида `exposure:<digest>` и не копирует raw `subject_id` в top-level колонку
outbox. Передайте секрет приложения для защиты от offline-перебора либо
реализуйте `AggregateIdStrategyInterface` для своей политики группировки.

Стандартная config-plugin factory настраивается через params приложения:

```php
'rasuvaeff/yii3-ab-testing-outbox' => [
    'aggregateIdSecret' => $_ENV['AB_AGGREGATE_SECRET'],
    'context' => [
        'allowedAttributes' => ['country', 'plan', 'email'],
        'renamedAttributes' => ['plan' => 'billing_plan'],
        'redactedAttributes' => ['email'],
    ],
],
```

Необязательный tracker-аргумент `eventId` передаёт стабильный domain event id в
`Outbox::record(id: ...)`. Повторная запись того же domain event сохраняет тот же
outbox/ClickHouse `event_id`; без него id генерируется и два вызова tracker'а
остаются двумя разными событиями. Нужное поведение duplicate id должно
обеспечивать выбранное storage приложения.

### Маршрутизация ClickHouse

Пакет поставляет совместимую ClickHouse-схему v1 в `migrations/` и совпадающий с
ней `AbTestingClickHouseRoutes::map()`. Таблицы по умолчанию называются
`ab_outbox_exposures` и `ab_outbox_conversions`: они намеренно отличаются от
несовместимых таблиц direct sink. Каждую строку предваряют две transport-meta
колонки: `event_id` (экспортёр заполняет её id сообщения для дедупликации через
`ReplacingMergeTree`) и `event_at` (время события из payload'а).

Примените DDL с теми же именами, которые переданы в route map:

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;

(new ClickHouseMigrationRunner(
    client: $clickHouseClient,
    migrationsPath: __DIR__ . '/vendor/rasuvaeff/yii3-ab-testing-outbox/migrations',
    placeholders: [
        'outbox_exposures_table' => AbTestingClickHouseRoutes::EXPOSURES_TABLE,
        'outbox_conversions_table' => AbTestingClickHouseRoutes::CONVERSIONS_TABLE,
    ],
))->run();

$router = new MapClickHouseMessageRouter(routes: AbTestingClickHouseRoutes::map());
```

Таблицы используют `ReplacingMergeTree ORDER BY event_id`, поэтому повторная
доставка одного outbox-сообщения при at-least-once семантике схлопывается во
время merge в ClickHouse. Время приёма `ingested_at` хранится отдельно от
payload-поля `event_at`.

До 1.2.4 route defaults были `ab_exposures` / `ab_conversions`, но пакет не
поставлял подходящий DDL, а имена пересекались с другой схемой direct sink.
Приложения, самостоятельно создавшие совместимые таблицы под старыми именами,
могут сохранить их, явно передав оба имени в `map()`. Никогда не направляйте
outbox-строки в таблицы direct-пакета.

### Yii3 DI

`config/di.php` биндит `ExposureTracker` и `ConversionTracker`. Биндите каждый
из **единственного** источника — установка рядом с другим tracker-бэкендом,
который тоже их биндит, вызывает ошибку `yiisoft/config` `Duplicate key`. Чтобы
использовать несколько приёмников одновременно, скомпонуйте их в конфиге
приложения:

```php
use Rasuvaeff\Yii3AbTesting\CompositeExposureTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;

return [
    ExposureTracker::class => static fn (Outbox $outbox, LoggerInterface $log): ExposureTracker
        => new CompositeExposureTracker(new OutboxExposureTracker($outbox), new LoggerExposureTracker($log)),
];
```

## Безопасность

- `subject_id` остаётся в analytics JSON payload как есть и может быть PII.
  Псевдонимный aggregate id только уменьшает его присутствие в top-level
  метаданных outbox, но не анонимизирует событие. Псевдонимизируйте значение до
  `Assignment`, если payload не должен содержать исходный идентификатор.
- В production всегда передавайте приватный aggregate-id secret. Стандартное
  пустое значение детерминировано и предотвращает случайную публикацию raw id,
  но не защищает предсказуемые subject id от словарного перебора.
- Атрибуты context запрещены по умолчанию. Используйте короткий allow-list,
  исключите секреты и высококардинальные значения, редактируйте их до записи в
  outbox storage.
- Payload'ы — это JSON-строки, записанные через outbox; `goal`/`experiment` —
  доверенные аналитические размерности из вашего приложения.

## Примеры

См. [`examples/`](examples/).

## Разработка

```bash
make build
make test
make test-coverage
make mutation
vendor/bin/testo --suite=Integration # требует CLICKHOUSE_HOST; в CI запускается с живым ClickHouse
```

Инструкции по Docker с монтированием корня монорепо см. в [AGENTS.md](AGENTS.md)
(path-репозиторий).

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
