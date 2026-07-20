<?php

declare(strict_types=1);

namespace KallioMicro\Middleware;

use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Http\ApiResponse;
use KallioMicro\Auth\Session;
use Closure;

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
