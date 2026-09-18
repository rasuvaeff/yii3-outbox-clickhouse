<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Double;

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriterInterface;
use Rasuvaeff\Yii3OutboxClickHouse\ClickHouseWriterFactoryInterface;

/**
 * A {@see RecordingWriterFactory} that runs a hook on every `create()`, with
 * the 1-based call number — the way a test injects "something happened while
 * batch N was being written", such as a stop request.
 */
final class HookedWriterFactory implements ClickHouseWriterFactoryInterface
{
    private int $calls = 0;

    /**
     * @param \Closure(int): void $onCreate
     */
    public function __construct(
        private readonly \Closure $onCreate,
        public readonly RecordingWriterFactory $inner = new RecordingWriterFactory(),
    ) {}

    #[\Override]
    public function create(string $table, array $columns): ClickHouseWriterInterface
    {
        ($this->onCreate)(++$this->calls);

        return $this->inner->create($table, $columns);
    }
}
