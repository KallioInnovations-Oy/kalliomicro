<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use KallioMicro\Http\Controller;
use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Http\ApiResponse;

class UserApiController extends Controller
{
    public function index(Request $request): Response
    {
        return ApiResponse::success()
            ->withData(['users' => []])
            ->toResponse();
    }

    public function me(Request $request): Response
    {
        $user = $this->user();

        if (!$user) {
            return ApiResponse::unauthorized('Not authenticated')->toResponse();
        }

        return ApiResponse::success()
            ->withData(['user' => $user])
            ->toResponse();
    }
}
