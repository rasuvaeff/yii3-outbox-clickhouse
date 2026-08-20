<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3Outbox\InMemoryStorage;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseMessageRouterInterface;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExporter;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseOutboxExportRunner;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHousePayloadDecoderInterface;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseWriterFactoryInterface;
use Rasuvaeff\Yii3OutboxClickHouse\Console\ExportClickHouseOutboxCommand;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultFailureDecider;
use Rasuvaeff\Yii3OutboxClickHouse\FailureDeciderInterface;
use Rasuvaeff\Yii3OutboxClickHouse\JsonPayloadDecoder;
use Rasuvaeff\Yii3OutboxClickHouse\MapClickHouseMessageRouter;
use Rasuvaeff\Yii3OutboxClickHouse\Tests\Double\RecordingWriterFactory;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * `config/*.php` is covered by neither psalm (src-only) nor cs, so the build
 * gate exercises the definitions here.
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    private const array ROUTES = [
        'ab.exposure' => ['table' => 'ab_exposures', 'columns' => ['event_id', 'experiment']],
    ];

    public function bindsOnlyItsOwnKeys(): void
    {
        // The storage backend binds StorageInterface; this package must not,
        // or yiisoft/config rejects the merge with a duplicate key.
        Assert::same(\array_keys($this->di()), [
            ClickHousePayloadDecoderInterface::class,
            FailureDeciderInterface::class,
            ClickHouseWriterFactoryInterface::class,
            ClickHouseMessageRouterInterface::class,
            ClickHouseOutboxExporter::class,
            ClickHouseOutboxExportRunner::class,
        ]);
    }

    public function theRouterFactoryUsesTheConfiguredRoutes(): void
    {
        $router = $this->router(['routes' => self::ROUTES]);

        Assert::instanceOf($router, MapClickHouseMessageRouter::class);
        Assert::same($router->handledTypes(), ['ab.exposure']);
    }

    public function theShippedDefaultRefusesToBuildARouter(): void
    {
        // params.php ships an empty routes map on purpose: it is an example,
        // not a usable configuration. Building the router from it must fail
        // loudly — an empty map would claim and terminally fail every message
        // in the outbox, other consumers' included.
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('must not be empty');

        $this->router($this->params()['rasuvaeff/yii3-outbox-clickhouse']);
    }

    public function theExporterFactoryBuildsFromTheContainerServices(): void
    {
        $definitions = $this->di(['routes' => self::ROUTES, 'fetchLimit' => 25]);
        $factory = $definitions[ClickHouseOutboxExporter::class];
        Assert::true(\is_callable($factory));

        $exporter = $factory(
            new InMemoryStorage(),
            $this->router(['routes' => self::ROUTES]),
            new StaticClock(new \DateTimeImmutable('2026-08-20T12:00:00+00:00')),
            new RecordingWriterFactory(),
            new DefaultFailureDecider(),
        );

        Assert::instanceOf($exporter, ClickHouseOutboxExporter::class);
        Assert::same($exporter->export()->totalHandled(), 0);
    }

    public function theRunnerFactoryHonoursTheConfiguredSleeps(): void
    {
        $definitions = $this->di(['routes' => self::ROUTES, 'idleSleepSeconds' => 0, 'busySleepSeconds' => 0]);
        $factory = $definitions[ClickHouseOutboxExportRunner::class];
        Assert::true(\is_callable($factory));

        $exporterFactory = $definitions[ClickHouseOutboxExporter::class];
        Assert::true(\is_callable($exporterFactory));

        $runner = $factory($exporterFactory(
            new InMemoryStorage(),
            $this->router(['routes' => self::ROUTES]),
            new StaticClock(new \DateTimeImmutable('2026-08-20T12:00:00+00:00')),
            new RecordingWriterFactory(),
            new DefaultFailureDecider(),
        ));

        Assert::instanceOf($runner, ClickHouseOutboxExportRunner::class);
        Assert::same($runner->runOnce()->totalHandled(), 0);
    }

    public function paramsRegisterTheExportCommandAndItsDefaults(): void
    {
        $params = $this->params();

        Assert::same(
            $params['yiisoft/yii-console']['commands']['outbox:clickhouse:export'],
            ExportClickHouseOutboxCommand::class,
        );

        $config = $params['rasuvaeff/yii3-outbox-clickhouse'];
        Assert::same($config['fetchLimit'], 1000);
        Assert::same($config['eventIdColumn'], 'event_id');
        Assert::same($config['retry'], ['maxAttempts' => 5, 'delaySeconds' => 30]);
        Assert::same($config['routes'], []);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function router(array $config): ClickHouseMessageRouterInterface
    {
        $factory = $this->di($config)[ClickHouseMessageRouterInterface::class];
        Assert::true(\is_callable($factory));

        /** @var ClickHouseMessageRouterInterface */
        return $factory(new JsonPayloadDecoder());
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function di(array $config = []): array
    {
        $params = ['rasuvaeff/yii3-outbox-clickhouse' => $config];

        return (static fn(array $params): array => require \dirname(__DIR__) . '/config/di.php')($params);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(): array
    {
        return require \dirname(__DIR__) . '/config/params.php';
    }
}
