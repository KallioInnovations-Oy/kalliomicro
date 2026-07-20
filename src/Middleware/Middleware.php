<?php

declare(strict_types=1);

namespace KallioMicro\Middleware;

/**
 * Abstract base class for middleware
 *
 * Carries nothing but the interface. It previously offered respond() and
 * next() helpers, both identity passthroughs (`return $response;` and
 * `return $next($request);`) that no shipped middleware ever called — each one
 * returns directly instead, which is shorter and clearer.
 *
 * Extending this is optional and always has been: docs/conventions.md tells
 * downstream middleware to implement MiddlewareInterface, and the container
 * resolves class-string middleware on that interface alone.
 */
abstract class Middleware implements MiddlewareInterface
{
}
