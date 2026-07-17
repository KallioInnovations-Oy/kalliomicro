<?php

declare(strict_types=1);

namespace Tests\Routing;

use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Routing\Router;
use RuntimeException;
use Tests\Support\RecordingMiddleware;
use Tests\TestCase;

class RouterDispatchTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = $this->app->make(Router::class);
    }

    public function testRouteMatchesAndExtractsParams(): void
    {
        $this->router->get('/users/{id}', fn (Request $request, string $id) => Response::html("user-{$id}"));

        $response = $this->router->dispatch(Request::create('/users/42'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('user-42', $response->getContent());
    }

    public function testZeroArgClosureHandlerWorks(): void
    {
        // Regression: Container::call() splatted named params blindly, so a
        // closure declaring no parameters fataled on "Unknown named parameter"
        $this->router->get('/static', fn () => Response::html('static-ok'));

        $response = $this->router->dispatch(Request::create('/static'));

        $this->assertSame('static-ok', $response->getContent());
    }

    public function testUnmatchedPathIs404(): void
    {
        $response = $this->router->dispatch(Request::create('/nope'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        $this->router->post('/items', fn (Request $request) => Response::html('created'));

        $response = $this->router->dispatch(Request::create('/items', 'GET'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertStringContainsString('POST', (string) $response->getHeader('allow'));
    }

    public function testClosureMiddlewareCanShortCircuit(): void
    {
        $this->router->get('/guarded', fn (Request $request) => Response::html('never'))
            ->middleware(fn (Request $request, \Closure $next) => Response::html('blocked', 403));

        $response = $this->router->dispatch(Request::create('/guarded'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('blocked', $response->getContent());
    }

    public function testClassStringMiddlewareResolvesAndAutoWires(): void
    {
        $this->router->get('/recorded', fn (Request $request) => Response::html('ok'))
            ->middleware(RecordingMiddleware::class);

        $response = $this->router->dispatch(Request::create('/recorded'));

        $this->assertSame('ok', $response->getContent());
        $this->assertSame('yes', $response->getHeader('x-recorded'));
    }

    public function testExceptionStatusCodesSurviveTheExceptionHandler(): void
    {
        // Regression: Application::handleException flattened every throw to
        // 500, breaking requireCsrf()'s 403 and HttpException::notFound()
        $this->router->get('/missing', function (Request $request) {
            throw \KallioMicro\Http\HttpException::notFound('gone');
        });
        $this->router->get('/csrf-like', function (Request $request) {
            throw new \RuntimeException('CSRF token mismatch', 403);
        });

        $this->assertSame(404, $this->app->handle(Request::create('/missing'))->getStatusCode());
        $this->assertSame(403, $this->app->handle(Request::create('/csrf-like'))->getStatusCode());
    }

    public function testAllShippedMiddlewareClassesAreAutoloadable(): void
    {
        // Class-string middleware only works if PSR-4 can find every class:
        // one class per file, filename matching the class (regression: Guest/
        // Role/Profile used to live inside AuthMiddleware.php and the
        // interface inside Middleware.php — unreachable by the autoloader)
        $this->assertTrue(interface_exists(\KallioMicro\Middleware\MiddlewareInterface::class));
        $this->assertTrue(class_exists(\KallioMicro\Middleware\AuthMiddleware::class));
        $this->assertTrue(class_exists(\KallioMicro\Middleware\GuestMiddleware::class));
        $this->assertTrue(class_exists(\KallioMicro\Middleware\RoleMiddleware::class));
        $this->assertTrue(class_exists(\KallioMicro\Middleware\ProfileMiddleware::class));
        $this->assertTrue(class_exists(\KallioMicro\Middleware\CsrfMiddleware::class));
    }

    public function testNonMiddlewareClassStringThrowsSelfDescribingError(): void
    {
        $this->router->get('/broken', fn (Request $request) => Response::html('ok'))
            ->middleware(\stdClass::class);

        try {
            $this->router->dispatch(Request::create('/broken'));
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('must implement', $e->getMessage());
            $this->assertStringContainsString('stdClass', $e->getMessage());
        }
    }
}
