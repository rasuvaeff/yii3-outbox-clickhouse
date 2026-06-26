<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseMessageRoute;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ClickHouseMessageRoute::class)]
final class ClickHouseMessageRouteTest
{
    public function groupKeyCombinesTableAndColumns(): void
    {
        $a = new ClickHouseMessageRoute(table: 't', columns: ['x', 'y'], row: ['x' => 1, 'y' => 2]);
        $b = new ClickHouseMessageRoute(table: 't', columns: ['x', 'y'], row: ['x' => 3, 'y' => 4]);
        $c = new ClickHouseMessageRoute(table: 't', columns: ['y', 'x'], row: ['y' => 1, 'x' => 2]);

        Assert::same($a->groupKey(), "t\0x\0y");
        Assert::same($b->groupKey(), $a->groupKey());
        Assert::notSame($c->groupKey(), $a->groupKey());
    }

    public function throwsOnEmptyTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseMessageRoute(table: '', columns: ['x'], row: []);
    }

    public function throwsOnEmptyColumns(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseMessageRoute(table: 't', columns: [], row: []);
    }
}
