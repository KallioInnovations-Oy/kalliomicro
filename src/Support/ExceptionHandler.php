<?php

declare(strict_types=1);

namespace KallioMicro\Support;

use KallioMicro\Http\Response;
use KallioMicro\Http\ApiResponse;

/**
 * ExceptionHandler - Unified error and exception handling
 *
 * Features:
 * - Custom exception handling with logging
 * - Error to exception conversion
 * - Different output formats (HTML, JSON, CLI)
 * - Optional notification on critical errors
 * - Stack trace sanitization for production
 */
class ExceptionHandler
{
    private ?Logger $logger;
    private ?Communicator $communicator;
    private bool $debug;
    private bool $notifyOnCritical;

    /** @var array<string> Paths to hide in stack traces */
    private array $hiddenPaths = [];

    /** @var callable|null Custom renderer */
    private $customRenderer = null;

    public function __construct(
        ?Logger $logger = null,
        ?Communicator $communicator = null,
        bool $debug = false,
        bool $notifyOnCritical = false
    ) {
        $this->logger = $logger;
        $this->communicator = $communicator;
        $this->debug = $debug;
        $this->notifyOnCritical = $notifyOnCritical;
    }

    /**
     * Register as the global exception and error handler
     */
    public function register(): self
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);

        return $this;
    }

    /**
     * Unregister handlers
     */
    public function unregister(): self
    {
        restore_exception_handler();
        restore_error_handler();

        return $this;
    }

    /**
     * Handle an exception
     */
    public function handleException(\Throwable $e): void
    {
        $this->logException($e);
        $this->notifyIfCritical($e);

        if ($this->isCli()) {
            $this->renderForCli($e);
        } elseif ($this->wantsJson()) {
            $this->renderForJson($e);
        } else {
            $this->renderForHtml($e);
        }
    }

    /**
     * Convert PHP errors to exceptions
     */
    public function handleError(
        int $level,
        string $message,
        string $file = '',
        int $line = 0
    ): bool {
        // Check if error should be reported
        if (!(error_reporting() & $level)) {
            return false;
        }

        throw new \ErrorException($message, 0, $level, $file, $line);
    }

    /**
     * Handle fatal errors on shutdown
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && $this->isFatalError($error['type'])) {
            $exception = new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            );

            $this->handleException($exception);
        }
    }

    /**
     * Report an exception without rendering
     */
    public function report(\Throwable $e): void
    {
        $this->logException($e);
        $this->notifyIfCritical($e);
    }

    /**
     * Render an exception to a Response
     */
    public function render(\Throwable $e): Response
    {
        if ($this->customRenderer !== null) {
            return ($this->customRenderer)($e, $this);
        }

        if ($this->wantsJson()) {
            return $this->createJsonResponse($e);
        }

        return $this->createHtmlResponse($e);
    }

    /**
     * Log the exception
     */
    private function logException(\Throwable $e): void
    {
        if ($this->logger === null) {
            return;
        }

        $context = [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'source' => 'ExceptionHandler',
        ];

        if ($this->debug) {
            $context['trace'] = $this->sanitizeTrace($e->getTraceAsString());
        }

        $level = $this->getLogLevel($e);

        match ($level) {
            Logger::LEVEL_ERROR => $this->logger->error($e->getMessage(), $context),
            Logger::LEVEL_WARNING => $this->logger->warning($e->getMessage(), $context),
            default => $this->logger->info($e->getMessage(), $context),
        };
    }

    /**
     * Send notification for critical exceptions
     */
    private function notifyIfCritical(\Throwable $e): void
    {
        if (!$this->notifyOnCritical || $this->communicator === null) {
            return;
        }

        if (!$this->isCritical($e)) {
            return;
        }

        $title = 'Critical Error: ' . get_class($e);
        $text = sprintf(
            "**Message:** %s\n\n**File:** %s:%d\n\n**Time:** %s",
            $e->getMessage(),
            $this->sanitizePath($e->getFile()),
            $e->getLine(),
            date('Y-m-d H:i:s')
        );

        $this->communicator->sendTeamsNotification($title, $text, null, 'FF0000');
    }

    /**
     * Render exception for CLI output
     */
    private function renderForCli(\Throwable $e): void
    {
        $output = "\n";
        $output .= "\033[31m" . get_class($e) . "\033[0m\n";
        $output .= $e->getMessage() . "\n\n";
        $output .= "at " . $e->getFile() . ":" . $e->getLine() . "\n";

        if ($this->debug) {
            $output .= "\nStack trace:\n";
            $output .= $this->sanitizeTrace($e->getTraceAsString()) . "\n";
        }

        fwrite(STDERR, $output);
        exit(1);
    }

    /**
     * Render exception as JSON response
     */
    private function renderForJson(\Throwable $e): void
    {
        $response = $this->createJsonResponse($e);
        $this->sendResponse($response);
    }

    /**
     * Render exception as HTML response
     */
    private function renderForHtml(\Throwable $e): void
    {
        $response = $this->createHtmlResponse($e);
        $this->sendResponse($response);
    }

    /**
     * Create JSON response for exception
     */
    private function createJsonResponse(\Throwable $e): Response
    {
        $httpCode = $this->getHttpCode($e);

        $data = [
            'error' => true,
            'message' => $this->debug ? $e->getMessage() : $this->getGenericMessage($httpCode),
        ];

        if ($this->debug) {
            $data['exception'] = get_class($e);
            $data['file'] = $this->sanitizePath($e->getFile());
            $data['line'] = $e->getLine();
            $data['trace'] = $this->getTraceArray($e);
        }

        return ApiResponse::error($data['message'], $httpCode)
            ->withData($this->debug ? $data : [])
            ->toResponse();
    }

    /**
     * Create HTML response for exception
     */
    private function createHtmlResponse(\Throwable $e): Response
    {
        $httpCode = $this->getHttpCode($e);

        if ($this->debug) {
            $html = $this->renderDebugPage($e);
        } else {
            $html = $this->renderErrorPage($httpCode);
        }

        return Response::html($html, $httpCode);
    }

    /**
     * Render detailed debug page
     */
    private function renderDebugPage(\Throwable $e): string
    {
        $class = htmlspecialchars(get_class($e));
        $message = htmlspecialchars($e->getMessage());
        $file = htmlspecialchars($this->sanitizePath($e->getFile()));
        $line = $e->getLine();
        $trace = htmlspecialchars($this->sanitizeTrace($e->getTraceAsString()));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - {$class}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1a1a2e; color: #eee; padding: 2rem; }
        .container { max-width: 1200px; margin: 0 auto; }
        .error-header { background: linear-gradient(135deg, #e74c3c, #c0392b); padding: 2rem; border-radius: 8px; margin-bottom: 1rem; }
        .error-class { font-size: 0.9rem; color: rgba(255,255,255,0.8); margin-bottom: 0.5rem; }
        .error-message { font-size: 1.5rem; font-weight: 600; }
        .error-location { background: #16213e; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; font-family: 'Fira Code', monospace; font-size: 0.9rem; }
        .error-location strong { color: #00d4ff; }
        .stack-trace { background: #0f0f23; padding: 1.5rem; border-radius: 8px; font-family: 'Fira Code', monospace; font-size: 0.85rem; white-space: pre-wrap; overflow-x: auto; line-height: 1.6; }
        h2 { color: #00d4ff; margin-bottom: 1rem; font-size: 1rem; text-transform: uppercase; letter-spacing: 1px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="error-header">
            <div class="error-class">{$class}</div>
            <div class="error-message">{$message}</div>
        </div>
        <div class="error-location">
            <strong>{$file}</strong> on line <strong>{$line}</strong>
        </div>
        <h2>Stack Trace</h2>
        <div class="stack-trace">{$trace}</div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Render generic error page
     */
    private function renderErrorPage(int $httpCode): string
    {
        $title = $this->getGenericTitle($httpCode);
        $message = $this->getGenericMessage($httpCode);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$httpCode} - {$title}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .error-box { text-align: center; padding: 3rem; }
        .error-code { font-size: 6rem; font-weight: 700; color: #dee2e6; margin-bottom: 1rem; }
        .error-title { font-size: 1.5rem; color: #343a40; margin-bottom: 0.5rem; }
        .error-message { color: #6c757d; margin-bottom: 2rem; }
        a { color: #0d6efd; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="error-box">
        <div class="error-code">{$httpCode}</div>
        <div class="error-title">{$title}</div>
        <div class="error-message">{$message}</div>
        <a href="/">Return to homepage</a>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Send response and exit
     */
    private function sendResponse(Response $response): void
    {
        http_response_code($response->getStatusCode());

        // Response stores headers as name => string[] (multi-value)
        foreach ($response->getHeaders() as $name => $values) {
            foreach ((array) $values as $value) {
                header("{$name}: {$value}", false);
            }
        }

        echo $response->getContent();
        exit(1);
    }

    /**
     * Get HTTP status code for exception
     */
    public function getHttpCode(\Throwable $e): int
    {
        if ($e instanceof \KallioMicro\Http\HttpException) {
            return $e->getStatusCode();
        }

        // Honor an explicit HTTP status carried in the exception code
        // (e.g. RuntimeException('CSRF token mismatch', 403)).
        $code = $e->getCode();
        if (is_int($code) && $code >= 400 && $code <= 599) {
            return $code;
        }

        return match (true) {
            $e instanceof \InvalidArgumentException => 400,
            default => 500,
        };
    }

    /**
     * Get log level for exception
     */
    private function getLogLevel(\Throwable $e): int
    {
        if ($this->isCritical($e)) {
            return Logger::LEVEL_ERROR;
        }

        return match (true) {
            $e instanceof \InvalidArgumentException => Logger::LEVEL_WARNING,
            $e instanceof \RuntimeException => Logger::LEVEL_ERROR,
            default => Logger::LEVEL_ERROR,
        };
    }

    /**
     * Check if exception is critical
     */
    private function isCritical(\Throwable $e): bool
    {
        return $e instanceof \Error
            || $e instanceof \PDOException
            || $e instanceof \RuntimeException;
    }

    /**
     * Check if error type is fatal
     */
    private function isFatalError(int $type): bool
    {
        return in_array($type, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true);
    }

    /**
     * Check if running in CLI mode
     */
    private function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    /**
     * Check if request wants JSON response
     */
    private function wantsJson(): bool
    {
        if ($this->isCli()) {
            return false;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    /**
     * Sanitize file path for display
     */
    private function sanitizePath(string $path): string
    {
        foreach ($this->hiddenPaths as $hidden) {
            $path = str_replace($hidden, '...', $path);
        }

        return $path;
    }

    /**
     * Sanitize stack trace
     */
    private function sanitizeTrace(string $trace): string
    {
        foreach ($this->hiddenPaths as $hidden) {
            $trace = str_replace($hidden, '...', $trace);
        }

        return $trace;
    }

    /**
     * Get trace as array
     *
     * @return array<int, array<string, mixed>>
     */
    private function getTraceArray(\Throwable $e): array
    {
        return array_map(function ($frame) {
            return [
                'file' => isset($frame['file']) ? $this->sanitizePath($frame['file']) : null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ];
        }, $e->getTrace());
    }

    /**
     * Get generic error title
     */
    private function getGenericTitle(int $code): string
    {
        return match ($code) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            419 => 'Session Expired',
            422 => 'Validation Error',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            503 => 'Service Unavailable',
            default => 'Error',
        };
    }

    /**
     * Get generic error message
     */
    private function getGenericMessage(int $code): string
    {
        return match ($code) {
            400 => 'The request could not be understood.',
            401 => 'Authentication is required.',
            403 => 'You do not have permission to access this resource.',
            404 => 'The requested resource was not found.',
            405 => 'The request method is not supported.',
            419 => 'Your session has expired. Please refresh and try again.',
            422 => 'The provided data was invalid.',
            429 => 'Too many requests. Please try again later.',
            500 => 'An unexpected error occurred. Please try again later.',
            503 => 'The service is temporarily unavailable.',
            default => 'An error occurred.',
        };
    }

    // Configuration methods

    /**
     * Set debug mode
     */
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * Set paths to hide in stack traces
     *
     * @param array<string> $paths
     */
    public function setHiddenPaths(array $paths): self
    {
        $this->hiddenPaths = $paths;
        return $this;
    }

    /**
     * Set custom renderer
     */
    public function setRenderer(callable $renderer): self
    {
        $this->customRenderer = $renderer;
        return $this;
    }

    /**
     * Enable/disable critical error notifications
     */
    public function setNotifyOnCritical(bool $notify): self
    {
        $this->notifyOnCritical = $notify;
        return $this;
    }
}
