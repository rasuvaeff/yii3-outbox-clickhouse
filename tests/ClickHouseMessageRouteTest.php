<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseMessageRoute;

#[CoversClass(ClickHouseMessageRoute::class)]
final class ClickHouseMessageRouteTest extends TestCase
{
    #[Test]
    public function groupKeyCombinesTableAndColumns(): void
    {
        $a = new ClickHouseMessageRoute(table: 't', columns: ['x', 'y'], row: ['x' => 1, 'y' => 2]);
        $b = new ClickHouseMessageRoute(table: 't', columns: ['x', 'y'], row: ['x' => 3, 'y' => 4]);
        $c = new ClickHouseMessageRoute(table: 't', columns: ['y', 'x'], row: ['y' => 1, 'x' => 2]);

        $this->assertSame("t\0x\0y", $a->groupKey());
        $this->assertSame($a->groupKey(), $b->groupKey());
        $this->assertNotSame($a->groupKey(), $c->groupKey());
    }

    #[Test]
    public function throwsOnEmptyTable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClickHouseMessageRoute(table: '', columns: ['x'], row: []);
    }

    #[Test]
    public function throwsOnEmptyColumns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClickHouseMessageRoute(table: 't', columns: [], row: []);
    }
}
