<?php

declare(strict_types=1);

namespace KallioMicro\Http;

/**
 * HttpException - Exception with HTTP status code
 */
class HttpException extends \RuntimeException
{
    private int $statusCode;
    private array $headers;

    public function __construct(
        int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
        array $headers = []
    ) {
        $this->statusCode = $statusCode;
        $this->headers = $headers;

        parent::__construct($message ?: $this->getDefaultMessage($statusCode), 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    private function getDefaultMessage(int $code): string
    {
        return match ($code) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'HTTP Error',
        };
    }

    // Factory methods

    public static function badRequest(string $message = ''): self
    {
        return new self(400, $message);
    }

    public static function unauthorized(string $message = ''): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    public static function methodNotAllowed(string $message = ''): self
    {
        return new self(405, $message);
    }

    public static function validationError(string $message = ''): self
    {
        return new self(422, $message);
    }

    public static function tooManyRequests(string $message = ''): self
    {
        return new self(429, $message);
    }

    public static function serverError(string $message = ''): self
    {
        return new self(500, $message);
    }
}
