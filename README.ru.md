# rasuvaeff/yii3-outbox-clickhouse

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-outbox-clickhouse/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-outbox-clickhouse)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-outbox-clickhouse/downloads)](https://packagist.org/packages/rasuvaeff/yii3-outbox-clickhouse)
[![Build](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-outbox-clickhouse/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox-clickhouse)
[![License](https://poser.pugx.org/rasuvaeff/yii3-outbox-clickhouse/license)](https://packagist.org/packages/rasuvaeff/yii3-outbox-clickhouse)
[English version](README.md)

Пакетный экспортёр в ClickHouse для
[`rasuvaeff/yii3-outbox`](https://github.com/rasuvaeff/yii3-outbox). Воркер
вычитывает outbox и делает крупные пакетные вставки в ClickHouse, поэтому путь
запроса остаётся быстрым и надёжным, а сбои ClickHouse поглощаются механизмами
retry'а outbox'а. **Domain-агностичен** — переиспользуйте его для A/B-аналитики,
аудит-логов, продуктовых событий и любых append-only данных.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник,
> которым можно поделиться с моделью.

## Почему не писать в ClickHouse из запроса?

Сброс на каждый запрос порождает одну маленькую вставку на запрос — ClickHouse
плохо переносит множество мелких вставок, а авария ClickHouse ломает сам запрос.
Этот пакет вместо этого батчит **между** запросами из долговечного outbox и
повторяет попытки при сбоях. Для request-scoped прямого sink'а см.
`rasuvaeff/yii3-ab-testing-clickhouse`.

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-outbox` ^1.0, `rasuvaeff/clickhouse-toolkit` ^1.1
- `symfony/console` ^6.4 || ^7.0 (для команды воркера)
- PSR-18 HTTP-клиент + PSR-17 фабрики (например `guzzlehttp/guzzle`)

## Установка

```bash
composer require rasuvaeff/yii3-outbox-clickhouse
```

## Использование

### Воркер

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultClickHouseWriterFactory;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3Outbox\RetryPolicy;

$router = new MapClickHouseMessageRouter(routes: [
    'ab.exposure' => [
        'table' => 'ab_exposures',
        'columns' => ['event_id', 'experiment', 'variant', 'subject_id'],
    ],
]);

$exporter = new ClickHouseOutboxExporter(
    storage: $storage,            // a yii3-outbox StorageInterface (e.g. yii3-outbox-db)
    router: $router,
    retryPolicy: new RetryPolicy(maxAttempts: 5, delaySeconds: 30),
    clock: $clock,
    writerFactory: new DefaultClickHouseWriterFactory(
        clientFactory: new ClickHouseClientFactory(new ClickHouseConfig(host: 'clickhouse')),
        batchSize: 1000,
    ),
);

$result = $exporter->export();   // one batch
```

### Запуск воркера

Запустите цикл через встроенную console-команду (зарегистрированную для
`yiisoft/yii-console`, также работает в чистой Symfony Console):

```bash
./yii outbox:clickhouse:export                 # run forever
./yii outbox:clickhouse:export --once          # single batch (e.g. from cron)
./yii outbox:clickhouse:export --max-iterations=100
```

Или управляйте framework-агностичным `ClickHouseOutboxExportRunner` сами:

```php
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExportRunner;

$runner = new ClickHouseOutboxExportRunner($exporter, idleSleepSeconds: 5, busySleepSeconds: 1);
$runner->run(
    static fn (int $iteration): bool => true,                 // stop condition
    static fn (int $seconds): mixed => sleep($seconds),       // sleeper
);
```

### Маршрутизация

`MapClickHouseMessageRouter` отображает `type => [table, columns]`. Каждая строка
строится из декодированного JSON-payload'а в порядке колонок; настроенная колонка
`event_id` (имя по умолчанию `event_id`) заполняется из id сообщения, а не из
payload'а.

### Идемпотентность (at-least-once)

Доставка outbox — at-least-once: retry после частичной неудачи может вставить
строку дважды. Сделайте целевую таблицу `ReplacingMergeTree`, упорядоченной по id
события, чтобы дубликаты схлопывались при merge:

```sql
CREATE TABLE ab_exposures (
    event_id   String,
    experiment String,
    variant    String,
    subject_id String,
    ts         DateTime DEFAULT now()
) ENGINE = ReplacingMergeTree ORDER BY event_id;
```

### Семантика сбоев

| Сбой | Решение | Эффект |
|---|---|---|
| Неизвестный тип / некорректный payload / отсутствие поля (`ClickHouseRouteException`) | терминальный | `markFailed` |
| ClickHouse недоступен / транспортная ошибка (`ClickHouseWriteException`) | повторяемый | `save`, остаётся `Pending`, ретраится по `RetryPolicy` |
| Повторяемый сбой при исчерпанных попытках (`attempts >= maxAttempts`) | терминальный | `markFailed` |

RetryPolicy — это потолок, а не решающий: повторяемый вердикт на сообщении,
потратившем последнюю попытку, становится терминальным, а заклеймленное
сообщение с уже исчерпанными попытками помечается `Failed` сразу. Без этого
потолка авария длиннее `maxAttempts x delaySeconds` оставляла бы весь backlog
навсегда в `Pending` — переклеймленным и пропущенным на каждом прогоне, и алерт
по `Failed` не увидел бы ничего.

`FailureDecision` и `FailureDeciderInterface` — точка расширения за этой
таблицей: реализуйте интерфейс, чтобы классифицировать свои исключения, и
передайте его экспортёру (контейнер по умолчанию биндит `DefaultFailureDecider`).
Ограничение по попыткам применяется к любому вердикту вашего decider'а.

`export()` никогда не бросает исключения при аварии ClickHouse.
`ClickHouseExportResult` сообщает `published` / `retryScheduled` /
`terminalFailed` / `skipped` и подробности по каждой группе. Если вызывающему
нужно ловимое доменное исключение — `exportOrFail()` оборачивает неудачный батч в
`Exception\ClickHouseExportException` и несёт в себе объект результата.
`exportOrFail()` бросает на **любом** неопубликованном сообщении, включая
запланированный ретрай, — временная авария ClickHouse его тоже поднимает. Если
будить дежурного нужно только на невосстановимых сообщениях, проверяйте
`ClickHouseExportResult::hasTerminalFailures()`.

### Yii3 DI

`config/di.php` биндит экспортёр, роутер, декодер, failure-decider и фабрику
writer'ов. Он **не** биндит `StorageInterface` — им владеет storage-backend
(`yii3-outbox-db`) или приложение. Маршруты настраиваются в params — **по
умолчанию карта пустая, и `MapClickHouseMessageRouter` её отвергает**. Это
сделано намеренно: пустая карта не обслуживает ни одного типа, но пустой
`handledTypes()` означает для `claim()` «все типы», поэтому экспортёр вычерпал
бы весь outbox — включая сообщения других консюмеров — и терминально завалил бы
каждое из них за отсутствие маршрута. Настройте маршруты до запуска
`outbox:clickhouse:export`:

```php
// config/params.php
'rasuvaeff/yii3-outbox-clickhouse' => [
    'batchSize' => 1000,
    'fetchLimit' => 1000,
    'eventIdColumn' => 'event_id',
    'routes' => ['ab.exposure' => ['table' => 'ab_exposures', 'columns' => ['event_id', 'experiment']]],
    'retry' => ['maxAttempts' => 5, 'delaySeconds' => 30],
],
```

## Безопасность

- Идентификаторы таблиц/колонок и значения проходят через `clickhouse-toolkit`
  (параметризованные вставки, валидация идентификаторов).
- Payload'ы могут содержать PII; их удержание — ответственность дизайнера
  таблицы/схемы.
- Учётные данные ClickHouse хранятся в `ClickHouseConfig`, никогда в payload'ах.

## Примеры

См. [`examples/`](examples/).

## Разработка

```bash
make build
```

Ядро `yii3-outbox` пока не опубликовано и подключается через path-репозиторий —
см. [AGENTS.md](AGENTS.md) про запуск Docker с корнем монорепо.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
