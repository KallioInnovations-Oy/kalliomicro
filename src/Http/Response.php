<?php

declare(strict_types=1);

namespace KallioMicro\Http;

/**
 * Response - HTTP Response wrapper
 *
 * Provides a fluent interface for building HTTP responses
 * with support for JSON, HTML, redirects, and file downloads.
 */
class Response
{
    private string $content;
    private int $statusCode;
    private string $statusText;

    /** @var array<string, string[]> */
    private array $headers = [];

    /** @var array<string, array{value: string, expires: int, path: string, domain: string, secure: bool, httpOnly: bool, sameSite: string}> */
    private array $cookies = [];

    /** @var array<int, string> */
    private static array $statusTexts = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        410 => 'Gone',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    public function __construct(
        string $content = '',
        int $statusCode = 200,
        array $headers = []
    ) {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->statusText = self::$statusTexts[$statusCode] ?? 'Unknown';

        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
    }

    // Factory methods

    /**
     * Create a JSON response
     *
     * @param array<string, mixed>|object $data
     */
    public static function json(array|object $data, int $status = 200, int $flags = 0): self
    {
        $content = json_encode($data, $flags | JSON_UNESCAPED_UNICODE);

        if ($content === false) {
            throw new \RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
        }

        return (new self($content, $status))
            ->header('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * Create an HTML response
     */
    public static function html(string $content, int $status = 200): self
    {
        return (new self($content, $status))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Create a plain text response
     */
    public static function text(string $content, int $status = 200): self
    {
        return (new self($content, $status))
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Create an empty response
     */
    public static function noContent(): self
    {
        return new self('', 204);
    }

    /**
     * Create a redirect response
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return (new self('', $status))
            ->header('Location', $url);
    }

    /**
     * Create a file download response
     */
    public static function download(
        string $content,
        string $filename,
        string $contentType = 'application/octet-stream'
    ): self {
        return (new self($content, 200))
            ->header('Content-Type', $contentType)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->header('Content-Length', (string) strlen($content));
    }

    /**
     * Create a file response (inline)
     */
    public static function file(
        string $content,
        string $filename,
        string $contentType
    ): self {
        return (new self($content, 200))
            ->header('Content-Type', $contentType)
            ->header('Content-Disposition', "inline; filename=\"{$filename}\"")
            ->header('Content-Length', (string) strlen($content));
    }

    // Fluent setters

    public function status(int $code): self
    {
        $this->statusCode = $code;
        $this->statusText = self::$statusTexts[$code] ?? 'Unknown';
        return $this;
    }

    public function content(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Set a header (replaces existing)
     */
    public function header(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = [$value];
        return $this;
    }

    /**
     * Add a header (allows multiple values)
     */
    public function addHeader(string $name, string $value): self
    {
        $key = strtolower($name);
        if (!isset($this->headers[$key])) {
            $this->headers[$key] = [];
        }
        $this->headers[$key][] = $value;
        return $this;
    }

    /**
     * Set multiple headers
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
        return $this;
    }

    /**
     * Set a cookie
     */
    public function cookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
        $this->cookies[$name] = [
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
        ];
        return $this;
    }

    /**
     * Remove a cookie
     */
    public function forgetCookie(string $name, string $path = '/', string $domain = ''): self
    {
        return $this->cookie($name, '', time() - 3600, $path, $domain);
    }

    // Getters

    public function getContent(): string
    {
        return $this->content;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getStatusText(): string
    {
        return $this->statusText;
    }

    /**
     * @return array<string, string[]>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? null;
        return $values ? $values[0] : null;
    }

    // Status helpers

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isRedirection(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    public function isOk(): bool
    {
        return $this->statusCode === 200;
    }

    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    // Send response

    /**
     * Send the response to the client
     */
    public function send(): void
    {
        $this->sendHeaders();
        $this->sendContent();
    }

    private function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // Send status line
        header(
            sprintf('HTTP/1.1 %d %s', $this->statusCode, $this->statusText),
            true,
            $this->statusCode
        );

        // Send headers
        foreach ($this->headers as $name => $values) {
            $replace = true;
            foreach ($values as $value) {
                header("{$name}: {$value}", $replace);
                $replace = false;
            }
        }

        // Send cookies
        foreach ($this->cookies as $name => $cookie) {
            setcookie(
                $name,
                $cookie['value'],
                [
                    'expires' => $cookie['expires'],
                    'path' => $cookie['path'],
                    'domain' => $cookie['domain'],
                    'secure' => $cookie['secure'],
                    'httponly' => $cookie['httpOnly'],
                    'samesite' => $cookie['sameSite'],
                ]
            );
        }
    }

    private function sendContent(): void
    {
        echo $this->content;
    }
}
