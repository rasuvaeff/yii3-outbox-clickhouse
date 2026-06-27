<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxClickHouse\DefaultFailureDecider;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseRouteException;
use Rasuvaeff\Yii3OutboxClickHouse\FailureDecision;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(DefaultFailureDecider::class)]
final class DefaultFailureDeciderTest
{
    private DefaultFailureDecider $decider;

    private OutboxMessage $message;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->decider = new DefaultFailureDecider();
        $this->message = OutboxMessage::create(type: 'ab.exposure', payload: '{}', createdAt: new \DateTimeImmutable('2026-06-11 12:00:00'));
    }

    public function routeFailureIsTerminal(): void
    {
        Assert::same(
            $this->decider->decide($this->message, new ClickHouseRouteException('bad')),
            FailureDecision::Terminal,
        );
    }

    public function writeFailureIsRetryable(): void
    {
        Assert::same(
            $this->decider->decide($this->message, new ClickHouseWriteException('down')),
            FailureDecision::Retryable,
        );
    }

    public function unknownErrorIsRetryable(): void
    {
        Assert::same(
            $this->decider->decide($this->message, new \RuntimeException('boom')),
            FailureDecision::Retryable,
        );
    }
}
