<?php

declare(strict_types=1);

namespace App\Controllers;

use KallioMicro\Http\Controller;
use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Http\ApiResponse;

class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        if ($this->isAuthenticated()) {
            return $this->redirect('/app/dashboard');
        }

        return $this->render('auth.login', [
            'title' => 'Login',
        ]);
    }

    public function login(Request $request): Response
    {
        $validation = $this->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if (!$validation['valid']) {
            if ($this->wantsJson()) {
                return ApiResponse::validationError('Validation failed', $validation['errors'])->toResponse();
            }
            return $this->render('auth.login', [
                'title' => 'Login',
                'errors' => $validation['errors'],
            ]);
        }

        $result = auth()->attempt([
            'username' => $this->input('username'),
            'password' => $this->input('password'),
        ]);

        if ($result->isSuccess()) {
            $intendedUrl = $this->session?->pullIntendedUrl('/app/dashboard') ?? '/app/dashboard';

            if ($this->wantsJson()) {
                return ApiResponse::success('Login successful')
                    ->redirect($intendedUrl)
                    ->toResponse();
            }

            return $this->redirect($intendedUrl);
        }

        if ($this->wantsJson()) {
            return ApiResponse::error($result->getMessage(), 401)->toResponse();
        }

        return $this->render('auth.login', [
            'title' => 'Login',
            'error' => $result->getMessage(),
        ]);
    }

    public function logout(Request $request): Response
    {
        auth()->logout();

        if ($this->wantsJson()) {
            return ApiResponse::success('Logged out')
                ->redirect('/login')
                ->toResponse();
        }

        return $this->redirect('/login');
    }

    public function redirect(Request $request, string $provider): Response
    {
        try {
            $url = auth()->getAuthorizationUrl($provider);
            return Response::redirect($url);
        } catch (\RuntimeException $e) {
            return $this->render('auth.login', [
                'title' => 'Login',
                'error' => 'OAuth provider not configured: ' . $provider,
            ]);
        }
    }

    public function callback(Request $request, string $provider): Response
    {
        try {
            $result = auth()->handleOAuthCallback($provider, $request->queryAll());

            if ($result->isSuccess()) {
                return $this->redirect('/app/dashboard');
            }

            return $this->render('auth.login', [
                'title' => 'Login',
                'error' => $result->getMessage(),
            ]);
        } catch (\Exception $e) {
            return $this->render('auth.login', [
                'title' => 'Login',
                'error' => 'OAuth authentication failed: ' . $e->getMessage(),
            ]);
        }
    }
}
