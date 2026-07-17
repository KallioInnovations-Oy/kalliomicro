<?php

declare(strict_types=1);

namespace KallioMicro\Middleware;

use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Http\ApiResponse;
use KallioMicro\Auth\Session;
use Closure;

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
