<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultClickHouseWriterFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(DefaultClickHouseWriterFactory::class)]
final class DefaultClickHouseWriterFactoryTest
{
    public function createsAWriterForTheGivenTable(): void
    {
        $writer = $this->factory()->create('ab_exposures', ['event_id', 'experiment']);

        Assert::instanceOf($writer, ClickHouseWriterInterface::class);
    }

    public function rejectsNonPositiveBatchSize(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->factory(batchSize: 0);
    }

    public function allowsBatchSizeOfOne(): void
    {
        $factory = $this->factory(batchSize: 1);

        Assert::instanceOf($factory->create('t', ['a']), ClickHouseWriterInterface::class);
    }

    private function factory(int $batchSize = 1000): DefaultClickHouseWriterFactory
    {
        return new DefaultClickHouseWriterFactory(
            clientFactory: new ClickHouseClientFactory(new ClickHouseConfig(host: '127.0.0.1')),
            batchSize: $batchSize,
        );
    }
}
