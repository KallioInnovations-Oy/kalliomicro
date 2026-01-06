<?php

declare(strict_types=1);

/**
 * Global helper functions
 */

if (!function_exists('env')) {
    /**
     * Get environment variable with optional default
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        // Convert string representations to proper types
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('app')) {
    /**
     * Get the application instance or resolve a service
     */
    function app(?string $abstract = null): mixed
    {
        $app = \KallioMicro\Core\Application::getInstance();

        if ($abstract === null) {
            return $app;
        }

        return $app->make($abstract);
    }
}

if (!function_exists('config')) {
    /**
     * Get a configuration value
     */
    function config(string $key, mixed $default = null): mixed
    {
        return app('config')->get($key, $default);
    }
}

if (!function_exists('view')) {
    /**
     * Render a view
     *
     * @param array<string, mixed> $data
     */
    function view(string $template, array $data = []): string
    {
        return app('view')->render($template, $data);
    }
}

if (!function_exists('response')) {
    /**
     * Create a new response
     */
    function response(): \KallioMicro\Http\ApiResponse
    {
        return new \KallioMicro\Http\ApiResponse();
    }
}

if (!function_exists('redirect')) {
    /**
     * Create a redirect response
     */
    function redirect(string $url, int $status = 302): \KallioMicro\Http\Response
    {
        return \KallioMicro\Http\Response::redirect($url, $status);
    }
}

if (!function_exists('request')) {
    /**
     * Get the current request
     */
    function request(): \KallioMicro\Http\Request
    {
        return app(\KallioMicro\Http\Request::class);
    }
}

if (!function_exists('session')) {
    /**
     * Get session instance or value
     */
    function session(?string $key = null, mixed $default = null): mixed
    {
        $session = app(\KallioMicro\Auth\Session::class);

        if ($key === null) {
            return $session;
        }

        return $session->get($key, $default);
    }
}

if (!function_exists('auth')) {
    /**
     * Get auth manager
     */
    function auth(): \KallioMicro\Auth\AuthManager
    {
        return app(\KallioMicro\Auth\AuthManager::class);
    }
}

if (!function_exists('db')) {
    /**
     * Get database connection or start query builder
     */
    function db(?string $table = null): \KallioMicro\Database\Connection|\KallioMicro\Database\QueryBuilder
    {
        $connection = app(\KallioMicro\Database\Connection::class);

        if ($table !== null) {
            return $connection->table($table);
        }

        return $connection;
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Get the CSRF token
     */
    function csrf_token(): string
    {
        return session()->getCsrfToken();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Generate CSRF hidden field
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('e')) {
    /**
     * Escape HTML entities
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /**
     * Generate URL for a named route
     *
     * @param array<string, string> $params
     */
    function url(string $name, array $params = []): string
    {
        return app('router')->url($name, $params);
    }
}

if (!function_exists('asset')) {
    /**
     * Generate URL for an asset
     */
    function asset(string $path): string
    {
        $baseUrl = config('app.url', '');
        return rtrim($baseUrl, '/') . '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('dd')) {
    /**
     * Dump and die (for debugging)
     */
    function dd(mixed ...$vars): never
    {
        foreach ($vars as $var) {
            echo '<pre>';
            var_dump($var);
            echo '</pre>';
        }
        exit(1);
    }
}

if (!function_exists('dump')) {
    /**
     * Dump (for debugging, without dying)
     */
    function dump(mixed ...$vars): void
    {
        foreach ($vars as $var) {
            echo '<pre>';
            var_dump($var);
            echo '</pre>';
        }
    }
}
