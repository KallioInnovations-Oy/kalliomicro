<?php

declare(strict_types=1);

namespace KallioMicro\Http;

/**
 * Request - HTTP Request wrapper
 *
 * Provides a clean, object-oriented interface to HTTP request data
 * with input sanitization and validation helpers.
 */
class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private string $queryString;

    /** @var array<string, mixed> */
    private array $query;

    /** @var array<string, mixed> */
    private array $post;

    /** @var array<string, mixed> */
    private array $server;

    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, mixed> */
    private array $cookies;

    /** @var array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> */
    private array $files;

    private ?string $content = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, string> */
    private array $routeParams = [];

    /** @var string[] Peers (REMOTE_ADDR, exact match) whose X-Forwarded-For may be trusted */
    private array $trustedProxies = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     */
    public function __construct(
        array $query = [],
        array $post = [],
        array $server = [],
        array $cookies = [],
        array $files = [],
        ?string $content = null
    ) {
        $this->query = $query;
        $this->post = $post;
        $this->server = $server;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->content = $content;

        $this->method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');

        // Method spoofing: a POST may promote itself to PUT/PATCH/DELETE via the
        // _method field ($view->method('PUT')). Only POST can spoof, and only to
        // those three verbs — a real PUT/PATCH/DELETE is never demoted.
        if ($this->method === 'POST' && isset($post['_method'])) {
            $override = strtoupper((string) $post['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $this->method = $override;
            }
        }

        $this->uri = $server['REQUEST_URI'] ?? '/';
        $this->queryString = $server['QUERY_STRING'] ?? '';

        // Parse path from URI (remove query string)
        $this->path = parse_url($this->uri, PHP_URL_PATH) ?: '/';

        // Extract headers from server
        $this->headers = $this->extractHeaders($server);
    }

    /**
     * Create request from PHP globals
     */
    public static function capture(): self
    {
        return new self(
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
            $_FILES,
            file_get_contents('php://input') ?: null
        );
    }

    /**
     * Create a request for testing
     *
     * @param array<string, mixed> $parameters
     * @param array<string, string> $headers
     */
    public static function create(
        string $uri,
        string $method = 'GET',
        array $parameters = [],
        array $headers = [],
        ?string $content = null
    ): self {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => '',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => 80,
            'HTTP_HOST' => 'localhost',
        ];

        foreach ($headers as $key => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            $server[$key] = $value;
        }

        $query = [];
        $post = [];

        if ($method === 'GET') {
            $query = $parameters;
        } else {
            $post = $parameters;
        }

        return new self($query, $post, $server, [], [], $content);
    }

    /**
     * Extract headers from server array
     *
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $name = str_replace('_', '-', $key);
                $headers[strtolower($name)] = (string) $value;
            }
        }

        return $headers;
    }

    // Getters

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function queryString(): string
    {
        return $this->queryString;
    }

    public function url(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host = $this->header('host', 'localhost');
        return "{$scheme}://{$host}{$this->uri}";
    }

    public function fullUrl(): string
    {
        return $this->url();
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on'
            || ($this->server['SERVER_PORT'] ?? 80) == 443;
    }

    // Input access

    /**
     * Get a query parameter
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get all query parameters
     *
     * @return array<string, mixed>
     */
    public function queryAll(): array
    {
        return $this->query;
    }

    /**
     * Get a POST parameter
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Get all POST parameters
     *
     * @return array<string, mixed>
     */
    public function postAll(): array
    {
        return $this->post;
    }

    /**
     * Get input from POST, then GET
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * Get all input (POST + GET)
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    /**
     * Check if input exists
     */
    public function has(string $key): bool
    {
        return isset($this->post[$key]) || isset($this->query[$key]);
    }

    /**
     * Get only specific keys
     *
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    /**
     * Get all except specific keys
     *
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        $all = $this->all();
        return array_diff_key($all, array_flip($keys));
    }

    /**
     * Get input as a specific type
     */
    public function string(string $key, string $default = ''): string
    {
        return (string) $this->input($key, $default);
    }

    public function integer(string $key, int $default = 0): int
    {
        return (int) $this->input($key, $default);
    }

    public function float(string $key, float $default = 0.0): float
    {
        return (float) $this->input($key, $default);
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    // Headers

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $key): bool
    {
        return isset($this->headers[strtolower($key)]);
    }

    // Cookies

    public function cookie(string $key, ?string $default = null): ?string
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    // Files

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        $file = $this->files[$key] ?? null;
        return $file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE;
    }

    // Server

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    // Content

    public function content(): ?string
    {
        return $this->content;
    }

    /**
     * Get JSON decoded content
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if ($this->content === null) {
            return null;
        }

        $data = json_decode($this->content, true);
        return is_array($data) ? $data : null;
    }

    // Request type helpers

    public function expectsJson(): bool
    {
        $accept = $this->header('accept', '');
        return str_contains($accept, '/json') || str_contains($accept, '+json');
    }

    public function isJson(): bool
    {
        $contentType = $this->header('content-type', '');
        return str_contains($contentType, '/json') || str_contains($contentType, '+json');
    }

    public function isAjax(): bool
    {
        return $this->header('x-requested-with') === 'XMLHttpRequest';
    }

    public function wantsJson(): bool
    {
        return $this->expectsJson() || $this->isAjax();
    }

    // Route parameters

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    // Attributes

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    // Client info

    /**
     * @param string[] $proxies
     */
    public function setTrustedProxies(array $proxies): void
    {
        $this->trustedProxies = $proxies;
    }

    public function ip(): ?string
    {
        $remote = $this->server['REMOTE_ADDR'] ?? null;

        // X-Forwarded-For is client-supplied; honor it only when the direct
        // peer is a configured proxy (app.trusted_proxies, default: none).
        if ($remote === null || !in_array($remote, $this->trustedProxies, true)) {
            return $remote;
        }

        $forwarded = $this->header('x-forwarded-for');
        if ($forwarded === null || $forwarded === '') {
            return $remote;
        }

        // Proxies append to the chain, so the first entry may be forged by the
        // client. The rightmost entry that is not itself a trusted proxy is the
        // real client address.
        foreach (array_reverse(array_map('trim', explode(',', $forwarded))) as $ip) {
            if ($ip !== '' && !in_array($ip, $this->trustedProxies, true)) {
                return $ip;
            }
        }

        return $remote;
    }

    public function userAgent(): ?string
    {
        return $this->header('user-agent');
    }
}
