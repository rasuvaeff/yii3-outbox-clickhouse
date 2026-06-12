<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultClickHouseWriterFactory;

#[CoversClass(DefaultClickHouseWriterFactory::class)]
final class DefaultClickHouseWriterFactoryTest extends TestCase
{
    #[Test]
    public function createsAWriterForTheGivenTable(): void
    {
        $writer = $this->factory()->create('ab_exposures', ['event_id', 'experiment']);

        $this->assertInstanceOf(ClickHouseWriterInterface::class, $writer);
    }

    #[Test]
    public function rejectsNonPositiveBatchSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be at least 1, got 0');

        $this->factory(batchSize: 0);
    }

    #[Test]
    public function allowsBatchSizeOfOne(): void
    {
        // 1 is valid (`< 1`, not `<= 1`): must not throw.
        $factory = $this->factory(batchSize: 1);

        $this->assertInstanceOf(ClickHouseWriterInterface::class, $factory->create('t', ['a']));
    }

    private function factory(int $batchSize = 1000): DefaultClickHouseWriterFactory
    {
        return new DefaultClickHouseWriterFactory(
            clientFactory: new ClickHouseClientFactory(new ClickHouseConfig(host: '127.0.0.1')),
            batchSize: $batchSize,
        );
    }
}
