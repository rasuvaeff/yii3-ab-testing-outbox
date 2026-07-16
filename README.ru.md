# rasuvaeff/yii3-ab-testing-outbox
[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-ab-testing-outbox/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-outbox)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-ab-testing-outbox/downloads)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-outbox)
[![Build](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-ab-testing-outbox/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-ab-testing-outbox/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-ab-testing-outbox)
[![License](https://poser.pugx.org/rasuvaeff/yii3-ab-testing-outbox/license)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-outbox)
Records [`rasuvaeff/yii3-ab-testing`](https://github.com/rasuvaeff/yii3-ab-testing)
exposure and conversion events into [`rasuvaeff/yii3-outbox`](https://github.com/rasuvaeff/yii3-outbox)
как долговечные сообщения. Путь запроса остается быстрым и выдерживает сбои в аналитике;
 работник экспортирует исходящие сообщения асинхронно (например, с помощью `yii3-outbox-clickhouse`).

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API, которую вы можете использовать. @@ЛИНИЯ@@
## Прямая раковина против прочного трубопровода
| | Прямой | Прочный (этот пакет) |
 |---|---|---|
 | Пакет | `yii3-ab-testing-clickhouse` | `yii3-ab-testing-outbox` + `yii3-outbox(-db)` + `yii3-outbox-clickhouse` |
 | Пакетирование | по запросу | большой, перекрестный запрос |
 | Пережил сбой в работе ClickHouse | нет | да |
 | Настройка | минимальный | работник + хранилище исходящих | @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `rasuvaeff/yii3-ab-testing` ^1.2
 - `rasuvaeff/yii3-outbox` ^1.0
 - `psr/lock` ^1.0

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
### Полезная нагрузка
Сообщения `ab.exposure` / `ab.conversion` содержат объект JSON, имена полей которого
 соответствуют столбцам аналитики `yii3-ab-testing-clickhouse`:

```json
{"v":1,"event_at":"2026-06-12 10:00:00","experiment":"checkout","variant":"green","subject_id":"user-1","is_forced":0,"is_fallback":0,"is_sticky":0,"environment":"production"}
```
Ведущее поле `v` представляет собой версию транспортной мета-схемы (`DefaultAbTestingOutboxMessageFactory::PAYLOAD_VERSION`).
 Он **не** указан в столбцах `AbTestingClickHouseRoutes` и никогда не записывается в ClickHouse — он существует
, поэтому последующие потребители, читающие необработанные исходящие сообщения, могут обнаружить генерации схемы полезной нагрузки.
 Конверсии добавляют `"цель"`. Флаги: `0|1`; «окружающая среда» всегда присутствует.
 `event_at` — это время события (UTC `Y-m-d H:i:s`), отмечаемое при отслеживании — отличное
 от времени экспорта работника. @@ЛИНИЯ@@
### Маршрутизация ClickHouse
`AbTestingClickHouseRoutes::map()` возвращает готовую карту маршрутов для
 `yii3-outbox-clickhouse`. Каждую строку возглавляют два столбца транспортных мета-данных: `event_id`
 (заполняется экспортером из идентификатора сообщения для дедупликации `ReplacingMergeTree`) и
 `event_at` (время события из полезных данных):

```php
use Rasuvaeff\Yii3AbTestingOutbox\AbTestingClickHouseRoutes;

$router = new MapClickHouseMessageRouter(routes: AbTestingClickHouseRoutes::map());
```
### Yii3 ДИ
`config/di.php` связывает `ExposureTracker` и `ConversionTracker`. Свяжите каждый из
 **единственного** источника — установка этого рядом с другим бэкэндом трекера, который также
 связывает их, вызывает ошибку `yiisoft/config` `Дублировать ключ`. Чтобы использовать несколько приемников
 одновременно, скомпонуйте их в конфигурации вашего приложения:

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
- `subject_id` может быть PII; этот пакет никогда не хэширует его автоматически — политика конфиденциальности
 принадлежит приложению.
 — полезные данные представляют собой строки JSON, записываемые через исходящие сообщения; `цель`/`эксперимент` — это
 доверенные аналитические измерения вашего приложения. @@ЛИНИЯ@@
## Примеры
См. [`examples/`](examples/). @@ЛИНИЯ@@
## Разработка
```bash
make build
make test
make test-coverage
make mutation
```
См. [AGENTS.md](AGENTS.md) для вызова Docker с корнем монорепо (репо пути). @@ЛИНИЯ@@
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
