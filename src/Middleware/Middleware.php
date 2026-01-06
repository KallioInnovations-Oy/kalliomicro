<?php

declare(strict_types=1);

namespace KallioMicro\Middleware;

use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use Closure;

/**
 * Middleware - Base middleware interface and common middleware implementations
 */
interface MiddlewareInterface
{
    /**
     * Handle the request
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response;
}

/**
 * Abstract base class for middleware with helper methods
 */
abstract class Middleware implements MiddlewareInterface
{
    /**
     * Create a response and skip remaining middleware
     */
    protected function respond(Response $response): Response
    {
        return $response;
    }

    /**
     * Pass to next middleware
     */
    protected function next(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
