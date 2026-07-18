<?php

declare(strict_types=1);

namespace Tests\Core;

use Closure;
use KallioMicro\Core\Config;
use KallioMicro\Http\HttpException;
use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Routing\Router;
use RuntimeException;
use Tests\TestCase;

/**
 * Application::handleException() used to be a second, weaker error renderer
 * living alongside ExceptionHandler. It interpolated the exception message and
 * trace into HTML unescaped, shipped the raw trace (including call args) on the
 * JSON path, and returned the raw message for ANY 4xx in production. It also
 * built its response outside the global middleware pipeline, so security
 * headers vanished on exactly the responses an attacker provokes.
 */
class ExceptionResponseTest extends TestCase
{
    private const XSS_MESSAGE = '<img src=x onerror=alert(1)> token abc123';

    private function bootApp(bool $debug): void
    {
        $config = new Config(sys_get_temp_dir() . '/km-tests');
        $config->set('app.debug', $debug);
        $this->app->instance(Config::class, $config);

        $this->app->middleware(
            fn (Request $request, Closure $next): Response => $next($request)->header('X-Frame-Options', 'DENY')
        );

        $router = $this->app->make(Router::class);
        $router->get('/ok', fn () => Response::html('fine'));
        $router->get('/boom', function () {
            throw new RuntimeException(self::XSS_MESSAGE);
        });
        $router->get('/gone', function () {
            throw HttpException::notFound('internal detail: widget 42');
        });
        $this->app->instance(Router::class, $router);
    }

    /**
     * @dataProvider errorPaths
     */
    public function testGlobalMiddlewareRunsOnThrownErrorResponses(string $path, int $expectedStatus): void
    {
        $this->bootApp(false);

        $response = $this->app->handle(Request::create($path));

        $this->assertSame($expectedStatus, $response->getStatusCode());
        $this->assertSame('DENY', $response->getHeader('X-Frame-Options'));
    }

    public static function errorPaths(): array
    {
        return [
            'successful route'    => ['/ok', 200],
            'thrown 500'          => ['/boom', 500],
            'thrown HttpException' => ['/gone', 404],
            'router 404'          => ['/no-such-route', 404],
        ];
    }

    public function testDebugPageEscapesTheExceptionMessage(): void
    {
        $this->bootApp(true);

        $body = $this->app->handle(Request::create('/boom'))->getContent();

        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringContainsString('&lt;img src=x', $body);
    }

    public function testProductionPageLeaksNothingFromTheException(): void
    {
        $this->bootApp(false);

        $body = $this->app->handle(Request::create('/boom'))->getContent();

        $this->assertStringNotContainsString('abc123', $body);
        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
    }

    /**
     * The old branch was `$debug || $status < 500`, so every 4xx echoed its
     * raw exception message to the client in production.
     */
    public function testNonServerErrorDoesNotEchoItsMessageInProduction(): void
    {
        $this->bootApp(false);

        $response = $this->app->handle(
            Request::create('/gone', 'GET', [], ['Accept' => 'application/json'])
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('widget 42', $response->getContent());
    }

    public function testJsonDebugPayloadDoesNotShipCallArguments(): void
    {
        $this->bootApp(true);

        $response = $this->app->handle(
            Request::create('/boom', 'GET', [], ['Accept' => 'application/json'])
        );

        $this->assertStringNotContainsString('"args"', $response->getContent());
    }
}
