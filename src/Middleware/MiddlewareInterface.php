<?php

declare(strict_types=1);

namespace KallioMicro\Middleware;

use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use Closure;

/**
 * MiddlewareInterface - Contract for middleware
 *
 * Lives in its own file so PSR-4 can autoload it: userland middleware that
 * implements this interface directly (without extending Middleware) must not
 * depend on another framework file having been loaded first.
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
