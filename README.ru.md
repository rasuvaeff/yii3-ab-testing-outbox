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
$exposureTracker->trackExposure($assignment);             // durable, no network call
// later, on the goal:
$conversionTracker->trackConversion($assignment, goal: 'purchase');
```

### Payload

Сообщения `ab.exposure` / `ab.conversion` несут JSON-объект, имена полей
которого совпадают с аналитическими колонками `yii3-ab-testing-clickhouse`:

```json
{"v":1,"event_at":"2026-06-12 10:00:00","experiment":"checkout","variant":"green","subject_id":"user-1","is_forced":0,"is_fallback":0,"is_sticky":0,"environment":"production"}
```

Ведущее поле `v` — это версия схемы transport-meta
(`DefaultAbTestingOutboxMessageFactory::PAYLOAD_VERSION`). Она **не** указана в
колонках `AbTestingClickHouseRoutes` и никогда не пишется в ClickHouse — она
существует, чтобы downstream-консьюмеры, читающие сырые outbox-сообщения, могли
различать поколения схемы payload'а. Для конверсий добавляется `"goal"`. Флаги
имеют значения `0|1`; `environment` присутствует всегда. `event_at` — время
события (UTC `Y-m-d H:i:s`), фиксируемое при трекинге; оно отличается от времени
экспорта воркером.

### Маршрутизация ClickHouse

`AbTestingClickHouseRoutes::map()` возвращает готовую карту маршрутов для
`yii3-outbox-clickhouse`. Каждую строку предваряют две transport-meta колонки:
`event_id` (заполняется экспортёром из id сообщения — для дедупликации через
`ReplacingMergeTree`) и `event_at` (время события из payload'а):

```php
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;

$router = new MapClickHouseMessageRouter(routes: AbTestingClickHouseRoutes::map());
```

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

- **`subject_id` пишется в два места, а не в одно.** Это поле внутри JSON-payload
  *и* составная часть `aggregate_id` outbox-сообщения, а `aggregate_id` — это
  отдельная top-level колонка таблицы outbox:

  | Сообщение | `aggregate_id` |
  |---|---|
  | `ab.exposure` | `<experiment>:<subject_id>` |
  | `ab.conversion` | `<experiment>:<subject_id>:<goal>` |

  Если `subject_id` — PII, то и эта колонка тоже. Всё, что читает таблицу outbox
  не разбирая payload'ы — админка со списком сообщений по агрегату, строка лога,
  метка метрики, выгрузка для поддержки — раскрывает его. Политика редактирования
  или хранения, применённая только к payload, это место пропустит.

- `subject_id` может быть PII; пакет никогда не хэширует его сам —
  privacy-политика остаётся ответственностью приложения. Хэшируйте или
  псевдонимизируйте **до** того, как значение попадёт в `Assignment`, — тогда и
  payload, и `aggregate_id` понесут одно и то же безопасное значение. Хэшировать
  на стороне приёмника поздно: в строке outbox уже лежит оригинал.
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
```

Инструкции по Docker с монтированием корня монорепо см. в [AGENTS.md](AGENTS.md)
(path-репозиторий).

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
