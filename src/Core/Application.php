<?php

declare(strict_types=1);

namespace KallioMicro\Core;

use KallioMicro\Core\Container;
use KallioMicro\Core\Config;
use KallioMicro\Http\HttpException;
use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Support\ExceptionHandler;
use KallioMicro\Support\Logger;
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
    private const VERSION = '1.2.4';

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
            return new ViewEngine($this->resourcePath('views'));
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

        $this->applyTimezone();

        // Default file logger, so an unconfigured application still records
        // its own failures. Bind your own (database-backed, a different
        // channel) and the error handler below picks it up.
        if (!$this->has(Logger::class)) {
            $this->singleton(Logger::class, fn (): Logger => new Logger());
        }

        // Default error renderer, registered only if nothing else claimed the
        // binding — a boot callback that binds its own (custom renderer, hidden
        // paths, a logger) wins. Autowiring would otherwise construct one with
        // the constructor's debug=false and never show a debug page.
        //
        // The Logger is passed explicitly: without one, report() has nowhere to
        // write and every handled exception is silently discarded.
        if (!$this->has(ExceptionHandler::class)) {
            $this->singleton(ExceptionHandler::class, function (): ExceptionHandler {
                $debug = (bool) $this->make(Config::class)->get('app.debug', false);
                return new ExceptionHandler($this->make(Logger::class), null, $debug);
            });
        }

        $this->booted = true;
    }

    /**
     * Apply config('app.timezone') to PHP's default timezone
     *
     * `app.timezone` shipped in config/app.php from the start and nothing ever
     * read it — every date() call in every downstream silently used whatever
     * php.ini said instead. A config key that does nothing is worse than an
     * absent one: it reads as a setting that has been made.
     *
     * An unknown identifier raises rather than falling back, because the
     * failure it prevents is a whole application quietly running on the wrong
     * clock — the same hazard as the unasserted session time zone in
     * docs/database.md.
     */
    private function applyTimezone(): void
    {
        $timezone = $this->make(Config::class)->get('app.timezone');

        if ($timezone === null || $timezone === '') {
            return;
        }

        if (!is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new \InvalidArgumentException(sprintf(
                'config(\'app.timezone\') must be a valid timezone identifier, %s given. '
                . 'Use an IANA name such as "Europe/Helsinki" or "UTC".',
                is_string($timezone) ? "'{$timezone}'" : get_debug_type($timezone)
            ));
        }

        date_default_timezone_set($timezone);
    }

    /**
     * Handle an incoming HTTP request
     */
    public function handle(Request $request): Response
    {
        try {
            $this->boot();

            // The destination catches its own exceptions so the error response
            // travels back out through the global middleware stack. Built
            // outside it, error responses silently lose whatever global
            // middleware adds — security headers being the usual casualty, on
            // exactly the responses an attacker is most likely to provoke.
            return $this->runMiddleware($request, function (Request $request) {
                try {
                    return $this->dispatchToRouter($request);
                } catch (Throwable $e) {
                    return $this->handleException($e, $request);
                }
            });
        } catch (Throwable $e) {
            // Last resort: boot() failed, or a middleware threw before calling
            // $next. Nothing can run the pipeline for us here.
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

    /**
     * Render a thrown exception to a Response
     *
     * Delegates to ExceptionHandler, which is the single owner of error
     * rendering: it escapes the debug page, strips `args` from the trace, and
     * gates every message on debug alone. This method previously carried a
     * second, weaker copy of that logic — unescaped HTML, a raw trace on the
     * JSON path, and a `$status < 500` branch that leaked internal exception
     * messages to clients in production.
     *
     * ExceptionHandler is resolved from the container so a downstream project
     * can swap or configure it (custom renderer, hidden paths) via instance().
     */
    private function handleException(Throwable $e, Request $request): Response
    {
        $handler = $this->make(ExceptionHandler::class);

        // Report before rendering. Only ExceptionHandler's globally-registered
        // path logged, and public/index.php does not register it — so a
        // production 500 was rendered to the visitor and left no trace anywhere.
        //
        // Guarded even though report() swallows its own failures: a downstream
        // handler that logs to the database is a normal thing to bind, and the
        // database being down is a normal reason for the 500 in the first
        // place. A reporter that throws must not cost the visitor the error page.
        try {
            $handler->report($e);
        } catch (Throwable) {
            // Reporting is best-effort; rendering is not.
        }

        // requireCsrf() (403), HttpException::notFound() (404), etc. must
        // surface at their declared status — a blanket 500 breaks the
        // documented "abort with a specific HTTP status from anywhere" path.
        $response = $handler->render($e, $request->expectsJson());

        if ($e instanceof HttpException) {
            foreach ($e->getHeaders() as $name => $value) {
                $response->header($name, $value);
            }
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
