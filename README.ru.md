# rasuvaeff/yii3-outbox-clickhouse
[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-outbox-clickhouse/v/stable)](https://packagist.org/packages/rasuvaeff/yii3-outbox-clickhouse)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-outbox-clickhouse/downloads)](https://packagist.org/packages/rasuvaeff/yii3-outbox-clickhouse)
[![Build](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions)
[![Static analysis](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-outbox-clickhouse/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-outbox-clickhouse/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-outbox-clickhouse)
[![License](https://poser.pugx.org/rasuvaeff/yii3-outbox-clickhouse/license)](https://packagist.org/packages/rasuvaeff/yii3-outbox-clickhouse)
Batched ClickHouse exporter for [`rasuvaeff/yii3-outbox`](https://github.com/rasuvaeff/yii3-outbox).
Рабочий очищает исходящие сообщения и записывает большие пакетные вставки в ClickHouse, поэтому путь запроса
 остается быстрым и надежным, а сбои в работе ClickHouse компенсируются механизмом повторных попыток отправки исходящих сообщений
. **Независимость от домена** — повторно используйте его для A/B-аналитики, аудита журналов
, событий продукта и всего, что доступно только для добавления.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API, которую вы можете использовать. @@ЛИНИЯ@@
## Почему бы не написать в ClickHouse из запроса?
При очистке каждого запроса на каждый запрос создается одна небольшая вставка — ClickHouse ненавидит множество мелких вставок
, а сбой ClickHouse прерывает запрос. Вместо этого этот пакет
 группирует **по** запросы из надежного исходящего ящика и повторяет попытку в случае сбоя. Информацию о прямом приемнике
 на уровне запроса см. в `rasuvaeff/yii3-ab-testing-clickhouse`. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `rasuvaeff/yii3-outbox` ^1.0, `rasuvaeff/clickhouse-toolkit` ^1.1
 - `symfony/console` ^6.4 || ^7.0 (для рабочей команды)
 — HTTP-клиент PSR-18 + фабрики PSR-17 (например, `guzzlehttp/guzzle`)

## Установка
```bash
composer require rasuvaeff/yii3-outbox-clickhouse
```
## Использование
### Рабочий
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
### Рабочий
Запустите цикл с помощью встроенной консольной команды (зарегистрированной для `yiisoft/yii-console`,
 также работает в простой консоли Symfony):

```bash
./yii outbox:clickhouse:export                 # run forever
./yii outbox:clickhouse:export --once          # single batch (e.g. from cron)
./yii outbox:clickhouse:export --max-iterations=100
```
Или управляйте независимым от платформы ClickHouseOutboxExportRunner самостоятельно:

```php
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExportRunner;

$runner = new ClickHouseOutboxExportRunner($exporter, idleSleepSeconds: 5, busySleepSeconds: 1);
$runner->run(
    static fn (int $iteration): bool => true,                 // stop condition
    static fn (int $seconds): mixed => sleep($seconds),       // sleeper
);
```
### Маршрутизация
`MapClickHouseMessageRouter` отображает `type => [table, columns]`. Каждая строка строится
 из декодированных полезных данных JSON в порядке столбцов; настроенный столбец `event_id`
 (имя по умолчанию `event_id`) заполняется из идентификатора сообщения, а не из полезных данных. @@ЛИНИЯ@@
### Идемпотентность (хотя бы один раз)
Доставка в исходящие осуществляется как минимум один раз: при повторной попытке после частичного сбоя строка
 может быть вставлена ​​дважды. Сделайте целевую таблицу ReplacingMergeTree, упорядоченной по идентификатору события, чтобы дубликаты
 сворачивались при слиянии:

```sql
CREATE TABLE ab_exposures (
    event_id   String,
    experiment String,
    variant    String,
    subject_id String,
    ts         DateTime DEFAULT now()
) ENGINE = ReplacingMergeTree ORDER BY event_id;
```
### Семантика отказа
| Неудача | Решение | Эффект |
 |---|---|---|
 | Неизвестный тип/неверная полезная нагрузка/отсутствующее поле (`ClickHouseRouteException`) | терминал | `markFailed` |
 | ClickHouse отключен/ошибка транспорта (`ClickHouseWriteException`) | повторный | `сохранить`, остается в режиме ожидания, повторная попытка согласно `RetryPolicy` |

 `export()` никогда не вызывает сбой в работе ClickHouse. ClickHouseExportResult сообщает
 `published`/`retryScheduled`/`terminalFailed`/`skiped` и подробную информацию по каждой группе.
 Если вызывающему объекту требуется перехватываемое доменное исключение, `exportOrFail()` оборачивает неудачный пакет
 в `Exception\ClickHouseExportException` и переносит объект результата. @@ЛИНИЯ@@
### Yii3 ДИ
`config/di.php` связывает экспортер, маршрутизатор, декодер, средство решения ошибок и фабрику записи
. Он **не** связывает `StorageInterface` — который принадлежит серверу хранилища
 (`yii3-outbox-db`) или приложению. Настройте маршруты в параметрах:

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
- Идентификаторы и значения таблиц/столбцов проходят через `clickhouse-toolkit`
 (параметризованные вставки, проверка идентификатора).
 — полезные данные могут содержать персональные данные; сохранение является обязанностью
 разработчика таблицы/схемы.
 — учетные данные ClickHouse хранятся в ClickHouseConfig, а не в полезных нагрузках. @@ЛИНИЯ@@
## Примеры
См. [`examples/`](examples/). @@ЛИНИЯ@@
## Разработка
```bash
make build
```
Ядро `yii3-outbox` используется через репозиторий путей, пока оно не опубликовано — см.
 [AGENTS.md](AGENTS.md) для вызова Docker в монорепо-корне. @@ЛИНИЯ@@
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
