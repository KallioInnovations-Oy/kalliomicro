<?php

declare(strict_types=1);

namespace Tests\Http;

use KallioMicro\Http\ApiResponse;
use RuntimeException;
use Tests\TestCase;

class ApiResponseJsonTest extends TestCase
{
    public function testEncodablePayloadReturnsJson(): void
    {
        $json = ApiResponse::success('ok')->withData(['name' => 'Ähtäri'])->toJson();

        $decoded = json_decode($json, true);

        $this->assertTrue($decoded['success']);
        $this->assertSame('Ähtäri', $decoded['data']['name']);
        $this->assertStringContainsString('Ähtäri', $json, 'JSON_UNESCAPED_UNICODE must stay on');
    }

    public function testMalformedUtf8ThrowsANamedException(): void
    {
        // json_encode() returns false on malformed UTF-8, which used to be a
        // bare TypeError against the string return type — a 500 saying
        // nothing about the payload that caused it
        $response = ApiResponse::success('ok')->withData(['raw' => "\xB1\x31"]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ApiResponse payload could not be encoded as JSON');

        $response->toJson();
    }

    public function testRecursivePayloadThrowsRatherThanReturningFalse(): void
    {
        $payload = new \stdClass();
        $payload->self = $payload;

        $this->expectException(RuntimeException::class);

        ApiResponse::success('ok')->withData(['loop' => $payload])->toJson();
    }

    public function testCallerFlagsAreStillHonoured(): void
    {
        $json = ApiResponse::success('ok')->withData(['a' => 1])->toJson(JSON_PRETTY_PRINT);

        $this->assertStringContainsString("\n", $json);
    }
}
