<?php

declare(strict_types=1);

namespace KallioMicro\Middleware;

use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Http\ApiResponse;
use KallioMicro\Auth\Session;
use Closure;

/**
 * CsrfMiddleware - Validates CSRF tokens on state-changing requests
 *
 * Automatically skips GET, HEAD, OPTIONS requests as they should be safe.
 * Token can be provided via:
 * - POST parameter: csrf_token
 * - Header: X-CSRF-Token
 */
class CsrfMiddleware extends Middleware
{
    /** @var string[] HTTP methods that don't require CSRF verification */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var string[] */
    private array $except = [];

    public function __construct(
        private Session $session,
        array $except = []
    ) {
        $this->except = $except;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Skip safe methods
        if (in_array($request->method(), self::SAFE_METHODS)) {
            return $next($request);
        }

        // Skip excluded paths
        if ($this->isExcluded($request->path())) {
            return $next($request);
        }

        // Verify token
        if (!$this->verifyToken($request)) {
            return $this->handleFailure($request);
        }

        return $next($request);
    }

    /**
     * Check if path is in the exclusion list
     */
    private function isExcluded(string $path): bool
    {
        foreach ($this->except as $pattern) {
            if ($pattern === $path) {
                return true;
            }

            // Support wildcard patterns
            if (str_contains($pattern, '*')) {
                $regex = str_replace(['*', '/'], ['.*', '\/'], $pattern);
                if (preg_match("/^{$regex}$/", $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verify the CSRF token
     */
    private function verifyToken(Request $request): bool
    {
        // An empty csrf_token field must not shadow the X-CSRF-Token header
        // the JS client sends on every mutating request.
        $token = $request->input('csrf_token');
        if ($token === null || $token === '') {
            $token = $request->header('x-csrf-token');
        }

        if ($token === null || $token === '') {
            return false;
        }

        return $this->session->verifyCsrfToken($token);
    }

    /**
     * Handle CSRF verification failure
     */
    private function handleFailure(Request $request): Response
    {
        if ($request->wantsJson()) {
            return ApiResponse::error('CSRF token mismatch', 403)->toResponse();
        }

        return Response::html('<h1>403 Forbidden</h1><p>CSRF token mismatch.</p>', 403);
    }
}
