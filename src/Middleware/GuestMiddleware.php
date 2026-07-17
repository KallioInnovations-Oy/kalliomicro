<?php

declare(strict_types=1);

namespace KallioMicro\Middleware;

use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Http\ApiResponse;
use KallioMicro\Auth\Session;
use Closure;

/**
 * GuestMiddleware - Ensures user is NOT authenticated
 *
 * Useful for login/registration pages that should redirect if already logged in.
 */
class GuestMiddleware extends Middleware
{
    public function __construct(
        private Session $session,
        private string $homeUrl = '/'
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->session->isAuthenticated()) {
            if ($request->wantsJson()) {
                return ApiResponse::error('Already authenticated', 400)->toResponse();
            }

            return Response::redirect($this->homeUrl);
        }

        return $next($request);
    }
}
