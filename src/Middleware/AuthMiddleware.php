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

/**
 * RoleMiddleware - Ensures user has required role(s)
 */
class RoleMiddleware extends Middleware
{
    /** @var string[] */
    private array $roles;

    public function __construct(
        private Session $session,
        string ...$roles
    ) {
        $this->roles = $roles;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->session->isAuthenticated()) {
            return $this->handleUnauthorized($request, 'Authentication required');
        }

        $userRoles = $this->session->getUserRoles();

        // Check if user has any of the required roles
        $hasRole = !empty(array_intersect($this->roles, $userRoles));

        if (!$hasRole) {
            return $this->handleUnauthorized($request, 'Insufficient permissions');
        }

        return $next($request);
    }

    private function handleUnauthorized(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return ApiResponse::forbidden($message)->toResponse();
        }

        return Response::html("<h1>403 Forbidden</h1><p>{$message}</p>", 403);
    }
}

/**
 * ProfileMiddleware - Ensures user has specific profile/permission level
 */
class ProfileMiddleware extends Middleware
{
    /** @var int[] */
    private array $allowedProfiles;

    public function __construct(
        private Session $session,
        int ...$profileIds
    ) {
        $this->allowedProfiles = $profileIds;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->session->isAuthenticated()) {
            if ($request->wantsJson()) {
                return ApiResponse::unauthorized()->toResponse();
            }
            return Response::redirect('/login');
        }

        $userProfileId = $this->session->getProfileId();

        if (!in_array($userProfileId, $this->allowedProfiles)) {
            if ($request->wantsJson()) {
                return ApiResponse::forbidden('Access denied for your profile level')->toResponse();
            }
            return Response::html('<h1>403 Forbidden</h1><p>Access denied for your profile level.</p>', 403);
        }

        return $next($request);
    }
}
