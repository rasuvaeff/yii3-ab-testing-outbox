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

> **Сайт документации:** [rasuvaeff.github.io/yii3-ab-testing](https://rasuvaeff.github.io/yii3-ab-testing/)
> охватывает всё семейство, включая
> [матрицу интеграций](https://rasuvaeff.github.io/yii3-ab-testing/integration)
> для сборки своей комбинации (на английском).

## Durable против log shipping

У семейства два пути доставки, и оба пишут одинаковые строки в таблицы схемы v2
из `yii3-ab-testing-clickhouse`. Этот пакет — durable-путь.

| | Durable (этот пакет) | Log shipping |
|---|---|---|
| Пакеты | `yii3-ab-testing-outbox` + `yii3-outbox-db` + `yii3-outbox-clickhouse` | `LoggerExposureTracker` / `LoggerConversionTracker` из ядра + Vector / Fluent Bit |
| Батчинг | крупный, меж-запросный, силами воркера | силами коллектора |
| Переживает аварию ClickHouse | да | насколько буферизует коллектор |
| Настройка | воркер + outbox-хранилище | уже работающий конвейер логов |

Прямой writer в ClickHouse из `yii3-ab-testing-clickhouse` 1.x удалён в 2.0:
под PHP-FPM он вставлял несколько строк на каждый запрос внутри латентности
посетителя.

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^2.0
- `rasuvaeff/yii3-outbox` ^1.2
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
use Rasuvaeff\Yii3AbTestingOutbox\DefaultAbTestingOutboxMessageFactory;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxConversionTracker;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\PseudonymousAggregateIdStrategy;
use Rasuvaeff\Yii3Outbox\Outbox;

$outbox = new Outbox(storage: $storage, clock: $clock);   // storage from yii3-outbox-db
$messageFactory = new DefaultAbTestingOutboxMessageFactory(
    aggregateIdStrategy: new PseudonymousAggregateIdStrategy(secret: $_ENV['AB_AGGREGATE_SECRET']),
);
$ab = new AbTesting(
    provider: $provider,
    strategy: $strategy,
    exposureTracker: new OutboxExposureTracker($outbox, $messageFactory),
    conversionTracker: new OutboxConversionTracker($outbox, $messageFactory),
);

$assignment = $ab->assign(experiment: 'checkout', subjectId: $userId);
// Фасад минтит событие и вызывает трекер; идентичность едет вместе с ним,
// поэтому повтор одного доменного события остаётся одной строкой.
$exposure = $ab->trackExposure($assignment);
// позже, при достижении цели:
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

`PseudonymousAggregateIdStrategy` выдаёт стабильные HMAC-SHA-256 id вида
`exposure:<digest>` и не копирует raw `subject_id` в top-level колонку outbox.
Конструктор требует непустой секрет приложения; пустое или состоящее из пробелов
значение вызывает `InvalidArgumentException`. Для своей политики группировки
реализуйте `AggregateIdStrategyInterface`.

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

`AbTestingClickHouseRoutes::map()` — карта маршрутов для `yii3-outbox-clickhouse`.
Она направляет сообщения в таблицы схемы v2, которыми владеет
`yii3-ab-testing-clickhouse`, и берёт их имена и порядок колонок из
`AnalyticsSchemaV2`, а не дублирует их: INSERT перечисляет колонки по позиции,
и разошедшаяся копия списка молча писала бы значения не в те колонки.

```php
use Rasuvaeff\Yii3AbTestingClickHouse\SchemaMigrations;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;

(new SchemaMigrations($clickHouseClient))->apply();   // создаёт ab_exposures_v2 / ab_conversions_v2

$router = new MapClickHouseMessageRouter(routes: AbTestingClickHouseRoutes::map());
```

Воркер дренирует outbox командой `./yii outbox:clickhouse:export`.

`yii3-ab-testing-clickhouse` указан в `suggest`, а не в `require`: продюсеру,
который маршрутизирует сообщения не в ClickHouse, пакет схемы не нужен, а без
него `AbTestingClickHouseRoutes` просто не загружается.

**Обновление с payload'ов 1.x.** Сообщения, поставленные в очередь до
обновления, несут payload v1 без `decision_reason` и `dimensions`, поэтому в
таблицы v2 их не записать. `AbTestingClickHouseRoutes::legacyV1Map()` направляет
их в таблицы v1 `ab_outbox_exposures` / `ab_outbox_conversions`, DDL которых
ради этого по-прежнему лежит в `migrations/`. Держите обе карты, пока очередь не
опустеет, — см. [UPGRADE.md](UPGRADE.md).

### Yii3 DI

`config/di.php` биндит `ExposureTracker` и `ConversionTracker`. Биндите каждый
из **единственного** источника — установка рядом с другим tracker-бэкендом,
который тоже их биндит, вызывает ошибку `yiisoft/config` `Duplicate key`.
Фабрика сообщений, которую он внедряет, биндится здесь же и строится из
param'а `aggregateIdSecret`. Чтобы
использовать несколько приёмников одновременно, скомпонуйте их в конфиге
приложения:

```php
use Psr\Log\LoggerInterface;
use Rasuvaeff\Yii3AbTesting\CompositeExposureTracker;
use Rasuvaeff\Yii3AbTesting\ExposureTracker;
use Rasuvaeff\Yii3AbTesting\LoggerExposureTracker;
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingOutboxMessageFactoryInterface;
use Rasuvaeff\Yii3AbTestingOutbox\OutboxExposureTracker;
use Rasuvaeff\Yii3Outbox\Outbox;

return [
    ExposureTracker::class => static fn (
        Outbox $outbox,
        AbTestingOutboxMessageFactoryInterface $messageFactory,
        LoggerInterface $log,
    ): ExposureTracker => new CompositeExposureTracker(
        new OutboxExposureTracker($outbox, $messageFactory),
        new LoggerExposureTracker($log),
    ),
];
```

## Безопасность

- `subject_id` остаётся в analytics JSON payload как есть и может быть PII.
  Псевдонимный aggregate id только уменьшает его присутствие в top-level
  метаданных outbox, но не анонимизирует событие. Псевдонимизируйте значение до
  `Assignment`, если payload не должен содержать исходный идентификатор.
- Всегда передавайте приватный непустой aggregate-id secret. Пакет немедленно
  выбрасывает исключение при создании стратегии или config-plugin wiring, если
  секрет отсутствует или состоит из пробелов, поэтому небезопасный default не
  попадёт в production.
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
