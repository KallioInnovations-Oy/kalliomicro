<?php

declare(strict_types=1);

namespace Tests\Middleware;

use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Middleware\CsrfMiddleware;
use Tests\Support\StubSession;
use Tests\TestCase;

class CsrfMiddlewareTest extends TestCase
{
    private function middleware(): CsrfMiddleware
    {
        return new CsrfMiddleware(new StubSession('valid-token'));
    }

    private function next(): \Closure
    {
        return fn (Request $request): Response => Response::html('passed');
    }

    public function testGetRequestSkipsVerification(): void
    {
        $response = $this->middleware()->handle(Request::create('/x', 'GET'), $this->next());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('passed', $response->getContent());
    }

    public function testPostWithValidFieldTokenPasses(): void
    {
        $request = Request::create('/x', 'POST', ['csrf_token' => 'valid-token']);

        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEmptyFieldFallsThroughToHeader(): void
    {
        // An empty csrf_token field must not shadow the X-CSRF-Token header
        $request = Request::create('/x', 'POST', ['csrf_token' => ''], ['X-CSRF-Token' => 'valid-token']);

        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMissingTokenIsRejected(): void
    {
        $response = $this->middleware()->handle(Request::create('/x', 'POST'), $this->next());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testInvalidTokenIsRejected(): void
    {
        $request = Request::create('/x', 'POST', ['csrf_token' => 'wrong']);

        $response = $this->middleware()->handle($request, $this->next());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testExcludedPathSkipsVerification(): void
    {
        $middleware = new CsrfMiddleware(new StubSession('valid-token'), ['/webhooks/*']);

        $response = $middleware->handle(Request::create('/webhooks/github', 'POST'), $this->next());

        $this->assertSame(200, $response->getStatusCode());
    }
}
