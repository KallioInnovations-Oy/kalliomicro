<?php

declare(strict_types=1);

namespace Tests\Http;

use KallioMicro\Http\Request;
use Tests\Support\TestableController;
use Tests\TestCase;

class ControllerBackTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private function controller(array $headers): TestableController
    {
        $this->app->instance(Request::class, Request::create('/current', 'GET', [], $headers));
        return new TestableController($this->app);
    }

    public function testSameOriginRefererRedirectsToPathAndQuery(): void
    {
        // Request::create() sets Host: localhost
        $response = $this->controller(['Referer' => 'http://localhost/items?page=2'])->runBack();

        $this->assertSame('/items?page=2', $response->getHeader('location'));
    }

    public function testCrossOriginRefererFallsBackToHome(): void
    {
        $response = $this->controller(['Referer' => 'http://evil.example/items'])->runBack();

        $this->assertSame('/', $response->getHeader('location'));
    }

    public function testMissingRefererFallsBackToHome(): void
    {
        $response = $this->controller([])->runBack();

        $this->assertSame('/', $response->getHeader('location'));
    }

    public function testProtocolRelativeRefererFallsBackToHome(): void
    {
        $response = $this->controller(['Referer' => '//evil.example/items'])->runBack();

        $this->assertSame('/', $response->getHeader('location'));
    }

    public function testMissingHostHeaderDoesNotCrash(): void
    {
        // HTTP/1.0 clients and some probes send no Host header
        $this->app->instance(Request::class, new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/current',
            'HTTP_REFERER' => 'http://somewhere.example/items',
        ]));
        $response = (new TestableController($this->app))->runBack();

        $this->assertSame('/', $response->getHeader('location'));
    }

    public function testBracketedIpv6HostMatchesSameOrigin(): void
    {
        $this->app->instance(Request::class, new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/current',
            'HTTP_HOST' => '[::1]:8080',
            'HTTP_REFERER' => 'http://[::1]:8080/items?page=2',
        ]));
        $response = (new TestableController($this->app))->runBack();

        $this->assertSame('/items?page=2', $response->getHeader('location'));
    }
}
