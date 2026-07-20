<?php

declare(strict_types=1);

namespace Tests\Http;

use KallioMicro\Http\Request;
use Tests\TestCase;

class RequestIpTest extends TestCase
{
    /**
     * @param array<string, mixed> $server
     * @param string[] $trustedProxies
     */
    private function request(array $server, array $trustedProxies = []): Request
    {
        $request = new Request([], [], $server);
        $request->setTrustedProxies($trustedProxies);
        return $request;
    }

    public function testXffIgnoredWithoutTrustedProxies(): void
    {
        $request = $this->request([
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ]);

        $this->assertSame('203.0.113.7', $request->ip());
    }

    public function testXffIgnoredWhenPeerIsNotTrusted(): void
    {
        $request = $this->request([
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ], ['10.0.0.1']);

        $this->assertSame('203.0.113.7', $request->ip());
    }

    public function testForgedFirstEntryDoesNotWin(): void
    {
        // Client sent its own XFF ("6.6.6.6"), proxy appended the real address
        $request = $this->request([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.4',
        ], ['10.0.0.1']);

        $this->assertSame('198.51.100.4', $request->ip());
    }

    public function testTrustedChainEntriesAreSkipped(): void
    {
        $request = $this->request([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.4, 10.0.0.2',
        ], ['10.0.0.1', '10.0.0.2']);

        $this->assertSame('198.51.100.4', $request->ip());
    }

    public function testTrustedPeerWithoutXffReturnsRemoteAddr(): void
    {
        $request = $this->request(['REMOTE_ADDR' => '10.0.0.1'], ['10.0.0.1']);

        $this->assertSame('10.0.0.1', $request->ip());
    }
}
