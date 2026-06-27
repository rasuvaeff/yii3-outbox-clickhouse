<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseRouteException;
use Rasuvaeff\Yii3OutboxClickHouse\JsonPayloadDecoder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(JsonPayloadDecoder::class)]
final class JsonPayloadDecoderTest
{
    private JsonPayloadDecoder $decoder;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->decoder = new JsonPayloadDecoder();
    }

    public function decodesJsonObject(): void
    {
        $fields = $this->decoder->decode($this->message('{"experiment":"x","variant":"green","n":1}'));

        Assert::same($fields, ['experiment' => 'x', 'variant' => 'green', 'n' => 1]);
    }

    public function throwsOnInvalidJson(): void
    {
        Expect::exception(ClickHouseRouteException::class);

        $this->decoder->decode($this->message('{not json'));
    }

    public function throwsOnNonObjectPayload(): void
    {
        Expect::exception(ClickHouseRouteException::class);

        $this->decoder->decode($this->message('[1,2,3]'));
    }

    public function throwsOnScalarPayload(): void
    {
        Expect::exception(ClickHouseRouteException::class);

        $this->decoder->decode($this->message('42'));
    }

    private function message(string $payload): OutboxMessage
    {
        return OutboxMessage::create(type: 'ab.exposure', payload: $payload, createdAt: new \DateTimeImmutable('2026-06-11 12:00:00'));
    }
}
