<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests\Double;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        /** @var array<string, mixed> $context */
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
