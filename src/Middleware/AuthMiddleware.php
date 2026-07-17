<?php

declare(strict_types=1);

namespace KallioMicro\Middleware;

use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Http\ApiResponse;
use KallioMicro\Auth\Session;
use Closure;

/**
 * AuthMiddleware - Ensures user is authenticated
 *
 * Redirects to login page for web requests, returns 401 for API requests.
 */
class AuthMiddleware extends Middleware
{
    public function __construct(
        private Session $session,
        private string $loginUrl = '/login'
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->session->isAuthenticated()) {
            return $this->handleUnauthenticated($request);
        }

        return $next($request);
    }

    /**
     * Handle unauthenticated request
     */
    private function handleUnauthenticated(Request $request): Response
    {
        if ($request->wantsJson()) {
            return ApiResponse::unauthorized('Authentication required')->toResponse();
        }

        // Store intended URL for redirect after login
        $this->session->setIntendedUrl($request->url());

        return Response::redirect($this->loginUrl);
    }
}
