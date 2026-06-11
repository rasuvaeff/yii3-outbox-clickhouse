<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Double;

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseWriterFactoryInterface;

final class RecordingWriterFactory implements ClickHouseWriterFactoryInterface
{
    /**
     * @var list<array{table: string, columns: list<string>}>
     */
    public array $created = [];

    /**
     * @var array<string, RecordingWriter>
     */
    public array $writers = [];

    /**
     * @param array<string, \Throwable> $failTables table name => exception thrown on write
     */
    public function __construct(private readonly array $failTables = []) {}

    #[\Override]
    public function create(string $table, array $columns): ClickHouseWriterInterface
    {
        $this->created[] = ['table' => $table, 'columns' => $columns];

        $writer = new RecordingWriter($this->failTables[$table] ?? null);
        $this->writers[$table] = $writer;

        return $writer;
    }
}
