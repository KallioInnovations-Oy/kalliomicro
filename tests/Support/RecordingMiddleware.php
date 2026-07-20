<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use KallioMicro\Auth\Session;
use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Middleware\MiddlewareInterface;

/**
 * Class-string middleware fixture with a constructor dependency, proving the
 * container auto-wires middleware resolved from a class-string at dispatch.
 */
class RecordingMiddleware implements MiddlewareInterface
{
    public function __construct(private Session $session)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request)->header('X-Recorded', 'yes');
    }
}
