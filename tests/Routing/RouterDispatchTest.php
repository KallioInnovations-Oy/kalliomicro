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

    public function testHeadIsAnsweredByTheGetHandlerWithoutABody(): void
    {
        // Regression: HEAD matched no route and fell through to 405, so
        // `curl -I` and uptime monitors read a healthy endpoint as an outage
        $this->router->get('/health', fn (Request $request) => Response::json(['ok' => true]));

        $response = $this->router->dispatch(Request::create('/health', 'HEAD'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        // Headers survive the body strip, Content-Length included (RFC 9110 §9.3.2)
        $this->assertSame('application/json; charset=utf-8', $response->getHeader('content-type'));
        $this->assertSame((string) strlen('{"ok":true}'), $response->getHeader('content-length'));
    }

    public function testHeadRunsRouteMiddlewareAndRespectsAnExplicitHeadRoute(): void
    {
        $this->router->get('/guarded', fn (Request $request) => Response::html('body'))
            ->middleware(RecordingMiddleware::class);

        $response = $this->router->dispatch(Request::create('/guarded', 'HEAD'));

        $this->assertSame('yes', $response->getHeader('x-recorded'));

        // An explicitly registered HEAD route still wins over the GET fallback
        $this->router->get('/both', fn (Request $request) => Response::html('from-get'));
        $this->router->match(['HEAD'], '/both', fn (Request $request) => Response::html('x')->header('X-Source', 'head'));

        $this->assertSame('head', $this->router->dispatch(Request::create('/both', 'HEAD'))->getHeader('x-source'));
    }

    public function testOptionsIsAnsweredWithAllowInsteadOf405(): void
    {
        $this->router->get('/items', fn (Request $request) => Response::html('list'));
        $this->router->post('/items', fn (Request $request) => Response::html('created'));

        $response = $this->router->dispatch(Request::create('/items', 'OPTIONS'));

        $this->assertSame(204, $response->getStatusCode());
        $allow = (string) $response->getHeader('allow');
        foreach (['GET', 'POST', 'HEAD', 'OPTIONS'] as $method) {
            $this->assertStringContainsString($method, $allow);
        }
    }

    public function testUnknownPathStillIs404ForHeadAndOptions(): void
    {
        // The HEAD/OPTIONS fallbacks must not resurrect paths that do not exist
        $this->assertSame(404, $this->router->dispatch(Request::create('/nope', 'HEAD'))->getStatusCode());
        $this->assertSame(404, $this->router->dispatch(Request::create('/nope', 'OPTIONS'))->getStatusCode());
    }

    public function testAllowHeaderAdvertisesHeadAndOptionsOn405(): void
    {
        $this->router->get('/items', fn (Request $request) => Response::html('list'));

        $response = $this->router->dispatch(Request::create('/items', 'DELETE'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, HEAD, OPTIONS', $response->getHeader('allow'));
    }

    public function testLiteralPathSegmentsAreRegexEscaped(): void
    {
        // Regression: only "/" was escaped, so "." matched any character and
        // "/files/report.pdf" answered a request for "/files/reportXpdf"
        $this->router->get('/files/report.pdf', fn (Request $request) => Response::html('pdf'));

        $this->assertSame(200, $this->router->dispatch(Request::create('/files/report.pdf'))->getStatusCode());
        $this->assertSame(404, $this->router->dispatch(Request::create('/files/reportXpdf'))->getStatusCode());
    }

    public function testUnbalancedParenthesisInPathIsMatchableAndWarningFree(): void
    {
        // Regression: an unescaped "(" compiled to an invalid pattern — the
        // route was permanently unmatchable AND preg_match() warned on every
        // request, since dispatch re-tests every route on every dispatch
        $this->router->get('/legacy(v1)/ping', fn (Request $request) => Response::html('ping'));

        $warnings = [];
        set_error_handler(function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });

        try {
            $response = $this->router->dispatch(Request::create('/legacy(v1)/ping'));
        } finally {
            restore_error_handler();
        }

        $this->assertSame('ping', $response->getContent());
        $this->assertSame([], $warnings);
    }

    public function testRouteParametersAreUrlDecoded(): void
    {
        // Regression: a segment sent as "a%2Fb" reached the handler as the
        // literal "a%2Fb", so every lookup keyed on that parameter missed
        $this->router->get('/tags/{tag}', fn (Request $request, string $tag) => Response::html($tag));

        $this->assertSame('a/b', $this->router->dispatch(Request::create('/tags/a%2Fb'))->getContent());
        $this->assertSame('ä b', $this->router->dispatch(Request::create('/tags/%C3%A4%20b'))->getContent());
    }

    public function testGroupStateIsRestoredWhenTheCallbackThrows(): void
    {
        // Regression: without try/finally a throwing group callback leaked its
        // prefix and middleware onto every route registered afterwards
        try {
            $this->router->group(
                ['prefix' => '/admin', 'middleware' => [RecordingMiddleware::class]],
                function (Router $router): void {
                    throw new RuntimeException('boot failure inside the group');
                }
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('boot failure inside the group', $e->getMessage());
        }

        $this->router->get('/public', fn (Request $request) => Response::html('public'));

        $routes = $this->router->getRoutes();
        $route = end($routes);

        $this->assertSame('/public', $route->getPath());
        $this->assertSame([], $route->getMiddleware());
    }

    public function testUnsupportedHandlerReturnTypeThrowsInsteadOfInventingAResponse(): void
    {
        // Regression: the (string) fallback turned `return false` into a 200
        // with an empty text/plain body — a refused action read as a success
        $this->router->get('/refused', fn (Request $request) => false);

        try {
            $this->router->dispatch(Request::create('/refused'));
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('bool', $e->getMessage());
            $this->assertStringContainsString('expected Response', $e->getMessage());
        }
    }

    public function testDocumentedHandlerReturnTypesStillCoerce(): void
    {
        $this->router->get('/arr', fn (Request $request) => ['a' => 1]);
        $this->router->get('/str', fn (Request $request) => 'hello');
        $this->router->get('/nul', fn (Request $request) => null);

        $this->assertSame('{"a":1}', $this->router->dispatch(Request::create('/arr'))->getContent());
        $this->assertSame('hello', $this->router->dispatch(Request::create('/str'))->getContent());
        $this->assertSame(204, $this->router->dispatch(Request::create('/nul'))->getStatusCode());
    }
}
