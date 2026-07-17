<?php

declare(strict_types=1);

namespace KallioMicro\Routing;

use KallioMicro\Core\Application;
use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use Closure;
use RuntimeException;

/**
 * Router - Modern HTTP router with support for route groups and middleware
 *
 * Supports:
 * - RESTful routing (GET, POST, PUT, PATCH, DELETE)
 * - Route parameters with regex constraints
 * - Route groups with shared prefix/middleware
 * - Controller method routing
 * - Closure handlers
 */
class Router
{
    private Application $app;

    /** @var Route[] */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $namedRoutes = [];

    /** @var array<string, array<int, Closure|string>> */
    private array $groupMiddleware = [];

    private string $currentGroupPrefix = '';

    /** @var array<int, Closure|string> */
    private array $currentGroupMiddleware = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Register a GET route
     */
    public function get(string $path, array|Closure|string $handler): Route
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route
     */
    public function post(string $path, array|Closure|string $handler): Route
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route
     */
    public function put(string $path, array|Closure|string $handler): Route
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a PATCH route
     */
    public function patch(string $path, array|Closure|string $handler): Route
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Register a DELETE route
     */
    public function delete(string $path, array|Closure|string $handler): Route
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Register a route for multiple methods
     *
     * @param string[] $methods
     */
    public function match(array $methods, string $path, array|Closure|string $handler): Route
    {
        $route = null;
        foreach ($methods as $method) {
            $route = $this->addRoute(strtoupper($method), $path, $handler);
        }
        return $route;
    }

    /**
     * Register a route for all methods
     */
    public function any(string $path, array|Closure|string $handler): Route
    {
        return $this->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $handler);
    }

    /**
     * Register a resource controller (RESTful routes)
     *
     * @param class-string $controller
     */
    public function resource(string $path, string $controller): void
    {
        $path = rtrim($path, '/');

        $this->get($path, [$controller, 'index'])->name("{$path}.index");
        $this->get("{$path}/create", [$controller, 'create'])->name("{$path}.create");
        $this->post($path, [$controller, 'store'])->name("{$path}.store");
        $this->get("{$path}/{id}", [$controller, 'show'])->name("{$path}.show");
        $this->get("{$path}/{id}/edit", [$controller, 'edit'])->name("{$path}.edit");
        $this->put("{$path}/{id}", [$controller, 'update'])->name("{$path}.update");
        $this->delete("{$path}/{id}", [$controller, 'destroy'])->name("{$path}.destroy");
    }

    /**
     * Register an API resource (no create/edit views)
     *
     * @param class-string $controller
     */
    public function apiResource(string $path, string $controller): void
    {
        $path = rtrim($path, '/');

        $this->get($path, [$controller, 'index'])->name("{$path}.index");
        $this->post($path, [$controller, 'store'])->name("{$path}.store");
        $this->get("{$path}/{id}", [$controller, 'show'])->name("{$path}.show");
        $this->put("{$path}/{id}", [$controller, 'update'])->name("{$path}.update");
        $this->delete("{$path}/{id}", [$controller, 'destroy'])->name("{$path}.destroy");
    }

    /**
     * Create a route group with shared attributes
     *
     * The 'middleware' attribute takes closures or MiddlewareInterface
     * class-strings, same as Route::middleware().
     */
    public function group(array $attributes, Closure $callback): void
    {
        $previousPrefix = $this->currentGroupPrefix;
        $previousMiddleware = $this->currentGroupMiddleware;

        $this->currentGroupPrefix = $previousPrefix . ($attributes['prefix'] ?? '');
        $this->currentGroupMiddleware = array_merge(
            $previousMiddleware,
            $attributes['middleware'] ?? []
        );

        $callback($this);

        $this->currentGroupPrefix = $previousPrefix;
        $this->currentGroupMiddleware = $previousMiddleware;
    }

    /**
     * Add a route to the router
     */
    private function addRoute(string $method, string $path, array|Closure|string $handler): Route
    {
        $fullPath = $this->currentGroupPrefix . '/' . ltrim($path, '/');
        $fullPath = '/' . trim($fullPath, '/');

        $route = new Route($method, $fullPath, $handler);

        foreach ($this->currentGroupMiddleware as $middleware) {
            $route->middleware($middleware);
        }

        $this->routes[] = $route;

        return $route;
    }

    /**
     * Register a named route for URL generation
     */
    public function registerNamed(string $name, Route $route): void
    {
        $this->namedRoutes[$name] = $route;
    }

    /**
     * Generate URL for a named route
     *
     * @param array<string, string> $params
     */
    public function url(string $name, array $params = []): string
    {
        // Names set via Route::name() are resolved lazily on first lookup,
        // so routes registered after this Router was created still resolve.
        if (!isset($this->namedRoutes[$name])) {
            foreach ($this->routes as $route) {
                if ($route->getName() === $name) {
                    $this->namedRoutes[$name] = $route;
                    break;
                }
            }
        }

        if (!isset($this->namedRoutes[$name])) {
            throw new RuntimeException("Route [{$name}] not found.");
        }

        return $this->namedRoutes[$name]->generateUrl($params);
    }

    /**
     * Dispatch a request to the appropriate route
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();

        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if ($route->matches($method, $path)) {
                $params = $route->extractParams($path);
                $request->setRouteParams($params);

                return $this->runRoute($route, $request);
            }

            if ($route->matchesPath($path)) {
                $allowedMethods[$route->getMethod()] = true;
            }
        }

        if ($allowedMethods !== []) {
            return $this->handleMethodNotAllowed($request, array_keys($allowedMethods));
        }

        return $this->handleNotFound($request);
    }

    /**
     * Run a matched route through middleware and handler
     */
    private function runRoute(Route $route, Request $request): Response
    {
        $handler = $route->getHandler();
        $middleware = array_map($this->app->resolveMiddleware(...), $route->getMiddleware());

        // Build middleware pipeline
        $pipeline = array_reduce(
            array_reverse($middleware),
            function (Closure $next, Closure $mw) {
                return function (Request $request) use ($next, $mw) {
                    return $mw($request, $next);
                };
            },
            function (Request $request) use ($handler) {
                return $this->callHandler($handler, $request);
            }
        );

        return $pipeline($request);
    }

    /**
     * Call the route handler
     */
    private function callHandler(array|Closure|string $handler, Request $request): Response
    {
        // Closure handler
        if ($handler instanceof Closure) {
            $result = $this->app->call($handler, ['request' => $request] + $request->routeParams());
            return $this->toResponse($result);
        }

        // Controller@method string
        if (is_string($handler)) {
            [$controller, $method] = explode('@', $handler);
            $handler = [$controller, $method];
        }

        // [Controller::class, 'method'] array
        if (is_array($handler)) {
            [$controllerClass, $method] = $handler;
            $controller = $this->app->make($controllerClass);
            $result = $this->app->call([$controller, $method], ['request' => $request] + $request->routeParams());
            return $this->toResponse($result);
        }

        throw new RuntimeException('Invalid route handler');
    }

    /**
     * Convert handler result to Response
     */
    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        if ($result === null) {
            return Response::noContent();
        }

        return Response::text((string) $result);
    }

    /**
     * Handle 404 Not Found
     */
    private function handleNotFound(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json([
                'error' => true,
                'message' => 'Not Found',
            ], 404);
        }

        return Response::html('<h1>404 - Not Found</h1>', 404);
    }

    /**
     * Handle 405 Method Not Allowed (path exists under a different HTTP method)
     *
     * @param string[] $allowedMethods
     */
    private function handleMethodNotAllowed(Request $request, array $allowedMethods): Response
    {
        $allow = implode(', ', $allowedMethods);

        if ($request->wantsJson()) {
            return Response::json([
                'error' => true,
                'message' => 'Method Not Allowed',
            ], 405)->header('Allow', $allow);
        }

        return Response::html('<h1>405 - Method Not Allowed</h1>', 405)
            ->header('Allow', $allow);
    }

    /**
     * Get all registered routes
     *
     * @return Route[]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
