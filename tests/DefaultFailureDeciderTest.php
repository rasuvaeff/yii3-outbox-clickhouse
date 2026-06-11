<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultFailureDecider;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseRouteException;
use Rasuvaeff\Yii3OutboxClickHouse\FailureDecision;

#[CoversClass(DefaultFailureDecider::class)]
final class DefaultFailureDeciderTest extends TestCase
{
    private DefaultFailureDecider $decider;

    private OutboxMessage $message;

    #[\Override]
    protected function setUp(): void
    {
        $this->decider = new DefaultFailureDecider();
        $this->message = OutboxMessage::create(type: 'ab.exposure', payload: '{}', createdAt: new \DateTimeImmutable('2026-06-11 12:00:00'));
    }

    #[Test]
    public function routeFailureIsTerminal(): void
    {
        $this->assertSame(
            FailureDecision::Terminal,
            $this->decider->decide($this->message, new ClickHouseRouteException('bad')),
        );
    }

    #[Test]
    public function writeFailureIsRetryable(): void
    {
        $this->assertSame(
            FailureDecision::Retryable,
            $this->decider->decide($this->message, new ClickHouseWriteException('down')),
        );
    }

    #[Test]
    public function unknownErrorIsRetryable(): void
    {
        $this->assertSame(
            FailureDecision::Retryable,
            $this->decider->decide($this->message, new \RuntimeException('boom')),
        );
    }
}
