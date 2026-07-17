<?php

declare(strict_types=1);

namespace KallioMicro\Middleware;

use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use Closure;

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
