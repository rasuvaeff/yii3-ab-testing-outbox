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
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills)
> дополнительно получают agent skill этого пакета: он автоматически синкается в
> `.agents/skills/` при установке.

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
// Фасад минтит событие и вызывает трекер; идентичность едет вместе с ним,
// поэтому повтор одного доменного события остаётся одной строкой.
$exposure = $ab->trackExposure($assignment);
// later, on the goal:
$ab->trackConversion($assignment, goal: 'purchase', exposure: $exposure);
```

### Payload

Сообщения `ab.exposure` / `ab.conversion` несут каноническую строку схемы v2,
которую собирает `CanonicalEventSerializer` ядра — а не этот пакет. Это
сознательно: в v1 каждый путь доставки строил свой массив, они теряли разные
поля, и никто этого не замечал, пока данные уже не были испорчены.

```json
{"v":2,"event_id":"0198f2c1-4d3a-7c9e-8b21-6f4a2d9e0c17","occurred_at":"2026-08-01 10:00:00.123","experiment":"checkout","variant":"green","subject_id":"user-1","decision_reason":"assigned","assignment_source":"computed","experiment_revision":"db:7","environment":"production","dimensions":"{\"country\":\"RU\"}"}
```

Конверсии добавляют `goal` и `exposure_event_id`.

| Поле | Замечание |
|---|---|
| `v` | поколение схемы; транспортная мета, в ClickHouse не пишется |
| `event_id` | минтит ядро; ключ дедупликации всего конвейера |
| `occurred_at` | время события, а не экспорта — от него зависит ключ партиционирования |
| `decision_reason` | `assigned`, `forced`, `fallback_disabled`, `fallback_targeting_mismatch`; участием считается только `assigned` |
| `assignment_source` | `computed` или `store` (выдан sticky-хранилищем) |
| `dimensions` | JSON-**строка**, а не вложенный объект: экспортёр отвергает нескалярные поля payload |

Измерения фильтрует `AllowListAnalyticsContextPolicy` ядра, настраиваемая на
фасаде `AbTesting`. Она переехала туда в 2.0, чтобы один allow-list применялся
ко всем путям доставки; настроенная здесь, она фильтровала только durable-путь.

Потребители payload обязаны ветвиться по `v` и принимать каждую версию,
объявленную во время выката — как дренировать сообщения v1, поставленные в
очередь до апгрейда, см. [UPGRADE.md](UPGRADE.md).

`AbTestingOutboxEventType` фиксирует два типа сообщений (`ab.exposure`,
`ab.conversion`); `AbTestingOutboxPayload` — то, что фабрика передаёт в
`Outbox::record()`: тип, JSON-payload и aggregate id.

### Идентичность и повторы

Стандартный `PseudonymousAggregateIdStrategy` выдаёт стабильные HMAC-SHA-256 id
вида `exposure:<digest>` и не копирует raw `subject_id` в top-level колонку
outbox. Передайте секрет приложения для защиты от offline-перебора либо
реализуйте `AggregateIdStrategyInterface` для своей политики группировки.

Стандартная config-plugin factory настраивается через params приложения:

```php
'rasuvaeff/yii3-ab-testing-outbox' => [
    'aggregateIdSecret' => $_ENV['AB_AGGREGATE_SECRET'],
],
```

Измерения здесь больше не настраиваются — allow-list живёт на фасаде
`AbTesting`, поэтому применяется ко всем путям доставки, а не только к этому.

Идентификатор события приходит из самого события: ядро его минтит, трекер
пишет его в payload **и** передаёт в `Outbox::record(id: ...)`. Эти два значения
обязаны совпадать: экспортёр вправе заполнить колонку `event_id` из любого из
них, а расхождение расщепит одно событие на две строки, которые никогда не
схлопнутся.

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
