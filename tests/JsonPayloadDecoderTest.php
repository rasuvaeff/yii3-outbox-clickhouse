<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxClickHouse\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3Outbox\OutboxMessage;
use Rasuvaeff\Yii3OutboxClickHouse\Exception\ClickHouseRouteException;
use Rasuvaeff\Yii3OutboxClickHouse\JsonPayloadDecoder;

#[CoversClass(JsonPayloadDecoder::class)]
final class JsonPayloadDecoderTest extends TestCase
{
    private JsonPayloadDecoder $decoder;

    #[\Override]
    protected function setUp(): void
    {
        $this->decoder = new JsonPayloadDecoder();
    }

    #[Test]
    public function decodesJsonObject(): void
    {
        $fields = $this->decoder->decode($this->message('{"experiment":"x","variant":"green","n":1}'));

        $this->assertSame(['experiment' => 'x', 'variant' => 'green', 'n' => 1], $fields);
    }

    #[Test]
    public function throwsOnInvalidJson(): void
    {
        $this->expectException(ClickHouseRouteException::class);
        $this->expectExceptionMessage('Invalid JSON payload');

        $this->decoder->decode($this->message('{not json'));
    }

    #[Test]
    public function throwsOnNonObjectPayload(): void
    {
        $this->expectException(ClickHouseRouteException::class);
        $this->expectExceptionMessage('must be a JSON object');

        $this->decoder->decode($this->message('[1,2,3]'));
    }

    #[Test]
    public function throwsOnScalarPayload(): void
    {
        $this->expectException(ClickHouseRouteException::class);

        $this->decoder->decode($this->message('42'));
    }

    private function message(string $payload): OutboxMessage
    {
        return OutboxMessage::create(type: 'ab.exposure', payload: $payload, createdAt: new \DateTimeImmutable('2026-06-11 12:00:00'));
    }
}
