<?php

declare(strict_types=1);

namespace KallioMicro\Core;

use KallioMicro\Core\Container;
use KallioMicro\Core\Config;
use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Routing\Router;
use KallioMicro\Database\Connection;
use KallioMicro\View\ViewEngine;
use KallioMicro\Auth\Session;
use KallioMicro\Middleware\MiddlewareInterface;
use Closure;
use Throwable;

/**
 * Application - The main framework bootstrap and service container
 *
 * This class serves as the central point for the application, managing
 * service registration, middleware, and request handling.
 */
class Application extends Container
{
    private const VERSION = '1.1.0';

    private static ?Application $instance = null;

    private string $basePath;
    private bool $booted = false;

    /** @var array<class-string, Closure> */
    private array $bootCallbacks = [];

    /** @var array<int, Closure|string> */
    private array $middleware = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        self::$instance = $this;

        $this->registerBaseBindings();
        $this->registerCoreServices();
    }

    public static function getInstance(): ?Application
    {
        return self::$instance;
    }

    public static function version(): string
    {
        return self::VERSION;
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path ? '/' . ltrim($path, '/') : '');
    }

    public function configPath(string $path = ''): string
    {
        return $this->basePath('config' . ($path ? '/' . $path : ''));
    }

    public function publicPath(string $path = ''): string
    {
        return $this->basePath('public' . ($path ? '/' . $path : ''));
    }

    public function resourcePath(string $path = ''): string
    {
        return $this->basePath('resources' . ($path ? '/' . $path : ''));
    }

    public function storagePath(string $path = ''): string
    {
        return $this->basePath('storage' . ($path ? '/' . $path : ''));
    }

    private function registerBaseBindings(): void
    {
        $this->singleton(Application::class, fn() => $this);
        $this->alias(Application::class, 'app');
        $this->alias(Application::class, Container::class);
    }

    private function registerCoreServices(): void
    {
        // Configuration
        $this->singleton(Config::class, function () {
            return new Config($this->configPath());
        });
        $this->alias(Config::class, 'config');

        // Router
        $this->singleton(Router::class, function () {
            return new Router($this);
        });
        $this->alias(Router::class, 'router');

        // Request
        $this->singleton(Request::class, function () {
            $request = Request::capture();
            $request->setTrustedProxies(
                (array) $this->make(Config::class)->get('app.trusted_proxies', [])
            );
            return $request;
        });
        $this->alias(Request::class, 'request');

        // View Engine
        $this->singleton(ViewEngine::class, function () {
            return new ViewEngine(
                $this->resourcePath('views'),
                $this->storagePath('cache/views')
            );
        });
        $this->alias(ViewEngine::class, 'view');

        // Session
        $this->singleton(Session::class, function () {
            return new Session($this->make(Config::class));
        });
        $this->alias(Session::class, 'session');
    }

    /**
     * Register a database connection
     */
    public function registerDatabase(string $name, array $config): void
    {
        $this->singleton("db.{$name}", function () use ($config) {
            return new Connection($config);
        });

        // Set default database
        if ($name === 'default' || !$this->has('db')) {
            $this->alias("db.{$name}", 'db');
            $this->alias("db.{$name}", Connection::class);
        }
    }

    /**
     * Add global middleware
     *
     * Accepts a Closure(Request, Closure): Response, or a class-string of a
     * MiddlewareInterface implementation (resolved through the container when
     * the request is handled). Parameterized middleware use the closure form.
     */
    public function middleware(Closure|string $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Register a callback to run when application boots
     */
    public function booting(Closure $callback): void
    {
        $this->bootCallbacks[] = $callback;
    }

    /**
     * Boot the application
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->bootCallbacks as $callback) {
            $callback($this);
        }

        $this->booted = true;
    }

    /**
     * Handle an incoming HTTP request
     */
    public function handle(Request $request): Response
    {
        try {
            $this->boot();

            // Run through middleware stack
            $response = $this->runMiddleware($request, function (Request $request) {
                return $this->dispatchToRouter($request);
            });

            return $response;
        } catch (Throwable $e) {
            return $this->handleException($e, $request);
        }
    }

    private function runMiddleware(Request $request, Closure $destination): Response
    {
        $middleware = array_map($this->resolveMiddleware(...), $this->middleware);

        $pipeline = array_reduce(
            array_reverse($middleware),
            function (Closure $next, Closure $middleware) {
                return function (Request $request) use ($next, $middleware) {
                    return $middleware($request, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }

    /**
     * Normalize a middleware entry to a pipeline closure
     *
     * Single owner of the "what counts as valid middleware" contract — the
     * Router delegates here so route and global middleware can never drift.
     * Class-strings resolve through the container lazily, at invocation: a
     * middleware behind one that short-circuits is never constructed.
     */
    public function resolveMiddleware(Closure|string $middleware): Closure
    {
        if ($middleware instanceof Closure) {
            return $middleware;
        }

        return function (Request $request, Closure $next) use ($middleware): Response {
            $instance = $this->make($middleware);

            if (!$instance instanceof MiddlewareInterface) {
                throw new \RuntimeException(sprintf(
                    'Middleware [%s] must implement %s or be a Closure(Request, Closure): Response.',
                    $middleware,
                    MiddlewareInterface::class
                ));
            }

            return $instance->handle($request, $next);
        };
    }

    private function dispatchToRouter(Request $request): Response
    {
        $router = $this->make(Router::class);
        return $router->dispatch($request);
    }

    private function handleException(Throwable $e, Request $request): Response
    {
        $config = $this->make(Config::class);
        $debug = $config->get('app.debug', false);

        // requireCsrf() (403), HttpException::notFound() (404), etc. must
        // surface at their declared status — a blanket 500 breaks the
        // documented "abort with a specific HTTP status from anywhere" path.
        $status = \KallioMicro\Support\ExceptionHandler::getHttpCode($e);
        $headers = $e instanceof \KallioMicro\Http\HttpException ? $e->getHeaders() : [];

        if ($request->expectsJson()) {
            $response = Response::json([
                'error' => true,
                'message' => $debug || $status < 500 ? $e->getMessage() : 'Internal Server Error',
                'trace' => $debug ? $e->getTrace() : null,
            ], $status);
        } else {
            $response = Response::html('', $status);
            $response->content($debug
                ? sprintf('<h1>Error</h1><pre>%s</pre><pre>%s</pre>', $e->getMessage(), $e->getTraceAsString())
                : sprintf('<h1>%d - %s</h1>', $status, $response->getStatusText()));
        }

        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /**
     * Terminate the application
     */
    public function terminate(Request $request, Response $response): void
    {
        // Cleanup, logging, etc.
    }

    /**
     * Run the application and send response
     */
    public function run(): void
    {
        $request = $this->make(Request::class);
        $response = $this->handle($request);
        $response->send();
        $this->terminate($request, $response);
    }
}
