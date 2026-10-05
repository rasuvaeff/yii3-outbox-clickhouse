<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Support;

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseWriterFactoryInterface;

use function Rasuvaeff\Understudy\when;

/**
 * The writer side of the exporter tests, as understudy doubles: one factory
 * double answering a per-table writer double, so both the create calls and
 * the written rows are read back through {@see Understudy::calls()} instead
 * of public recording arrays. Replaces the deleted RecordingWriterFactory
 * and HookedWriterFactory.
 */
final class Writers
{
    private readonly ClickHouseWriterFactoryInterface $factory;

    /** @var array<string, ClickHouseWriterInterface> */
    private array $writers = [];

    private int $creates = 0;

    /**
     * @param array<string, \Throwable> $failTables table name => exception thrown from write()
     * @param null|\Closure(int): void $onCreate runs before the Nth create() is answered — the way a test injects
     *        "something happened while batch N was being written", such as a stop request
     */
    public function __construct(
        private readonly array $failTables = [],
        private readonly ?\Closure $onCreate = null,
    ) {
        $factory = Understudy::for(ClickHouseWriterFactoryInterface::class);
        when(fn() => $factory->create(Arg::any(), Arg::any()))
            ->answers(fn(Invocation $call): ClickHouseWriterInterface => $this->answer($call));

        $this->factory = $factory;
    }

    public function factory(): ClickHouseWriterFactoryInterface
    {
        return $this->factory;
    }

    /**
     * @return list<array{table: string, columns: list<string>}>
     */
    public function created(): array
    {
        return array_map(
            static fn(Invocation $call): array => ['table' => $call->arg('table'), 'columns' => $call->arg('columns')],
            Understudy::calls(fn() => $this->factory->create(Arg::any(), Arg::any())),
        );
    }

    /**
     * Every row handed to the table's writer, across all write() calls.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $table): array
    {
        $rows = [];

        foreach (Understudy::calls(fn() => $this->writerFor($table)->write(Arg::any())) as $call) {
            foreach ($call->arg('rows') as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function answer(Invocation $call): ClickHouseWriterInterface
    {
        if ($this->onCreate !== null) {
            ($this->onCreate)(++$this->creates);
        }

        return $this->writerFor($call->arg('table'));
    }

    private function writerFor(string $table): ClickHouseWriterInterface
    {
        if (isset($this->writers[$table])) {
            return $this->writers[$table];
        }

        $writer = Understudy::for(ClickHouseWriterInterface::class);

        if (isset($this->failTables[$table])) {
            when(fn() => $writer->write(Arg::any()))->throws($this->failTables[$table]);
        }

        return $this->writers[$table] = $writer;
    }
}
