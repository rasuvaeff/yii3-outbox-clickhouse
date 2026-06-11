<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Double;

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;

final class RecordingWriter implements ClickHouseWriterInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    public function __construct(private readonly ?\Throwable $failWith = null) {}

    #[\Override]
    public function write(iterable $rows): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        foreach ($rows as $row) {
            $this->rows[] = $row;
        }
    }
}
