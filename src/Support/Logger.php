<?php

declare(strict_types=1);

namespace KallioMicro\Support;

use KallioMicro\Database\Connection;

/**
 * Logger - Unified logging with database and file support
 *
 * Standalone logger aligned with KallioMicro status codes:
 * 0 = BYPASS (debug info that should always show)
 * 1 = SUCCESS
 * 2 = INFO
 * 3 = WARNING
 * 4 = ERROR
 *
 * Features:
 * - Database logging with file fallback
 * - Automatic context serialization
 * - Channel support for categorizing logs
 * - Configurable log levels
 * - PSR-3 compatible methods (without interface dependency)
 */
class Logger
{
    public const LEVEL_BYPASS = 0;
    public const LEVEL_SUCCESS = 1;
    public const LEVEL_INFO = 2;
    public const LEVEL_WARNING = 3;
    public const LEVEL_ERROR = 4;

    // PSR-3 compatible level constants
    public const EMERGENCY = 'emergency';
    public const ALERT = 'alert';
    public const CRITICAL = 'critical';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTICE = 'notice';
    public const INFO = 'info';
    public const DEBUG = 'debug';

    private ?Connection $db;
    private string $logFile;
    private string $logTable;
    private string $channel;
    private bool $useFileOnly = false;
    private int $minLevel;

    /** @var array<string, int> PSR-3 level string to KallioMicro level mapping */
    private const PSR_TO_KALLIOMICRO = [
        self::EMERGENCY => self::LEVEL_ERROR,
        self::ALERT => self::LEVEL_ERROR,
        self::CRITICAL => self::LEVEL_ERROR,
        self::ERROR => self::LEVEL_ERROR,
        self::WARNING => self::LEVEL_WARNING,
        self::NOTICE => self::LEVEL_INFO,
        self::INFO => self::LEVEL_INFO,
        self::DEBUG => self::LEVEL_BYPASS,
    ];

    /** @var array<int, string> */
    private const LEVEL_NAMES = [
        self::LEVEL_BYPASS => 'BYPASS',
        self::LEVEL_SUCCESS => 'SUCCESS',
        self::LEVEL_INFO => 'INFO',
        self::LEVEL_WARNING => 'WARNING',
        self::LEVEL_ERROR => 'ERROR',
    ];

    public function __construct(
        ?Connection $db = null,
        string $logFile = '',
        string $logTable = 'core_logs',
        string $channel = 'app',
        int $minLevel = self::LEVEL_BYPASS
    ) {
        $this->db = $db;
        $this->logTable = $logTable;
        $this->channel = $channel;
        $this->minLevel = $minLevel;
        $this->logFile = $logFile ?: $this->getDefaultLogPath();

        // Fall back to file-only if database is unavailable
        if ($db === null) {
            $this->useFileOnly = true;
        }
    }

    private function getDefaultLogPath(): string
    {
        $basePath = defined('KALLIOMICRO_BASE_PATH') ? KALLIOMICRO_BASE_PATH : dirname(__DIR__, 2);
        return $basePath . '/storage/logs/app.log';
    }

    /**
     * Log a message with KallioMicro status code
     *
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        // Convert PSR-3 level to KallioMicro level
        $kmLevel = is_int($level) ? $level : (self::PSR_TO_KALLIOMICRO[$level] ?? self::LEVEL_INFO);

        // Skip if below minimum level
        if ($kmLevel < $this->minLevel) {
            return;
        }

        $this->write($kmLevel, (string) $message, $context);
    }

    /**
     * Write log entry
     *
     * @param array<string, mixed> $context
     */
    private function write(int $level, string $message, array $context): void
    {
        // Interpolate context into message
        $message = $this->interpolate($message, $context);

        // Extract special context values, coercing rather than trusting the
        // caller's types. These are read as mixed and handed to int/?string/
        // string parameters under strict_types, so a context value of the
        // wrong type used to raise a TypeError out of write() — outside
        // writeToDatabase()'s try/catch, so it killed the caller instead of
        // falling back to file. A logging call must never do that, least of
        // all from inside ExceptionHandler::logException().
        $userId = $this->toInt($context['user_id'] ?? null) ?? $this->getUserId();
        $sourceId = isset($context['source_id']) && is_scalar($context['source_id'])
            ? (string) $context['source_id']
            : null;
        $source = isset($context['source']) && is_scalar($context['source'])
            ? (string) $context['source']
            : $this->channel;

        // Remove special keys from context
        unset($context['user_id'], $context['source_id'], $context['source']);

        if ($this->useFileOnly) {
            $this->writeToFile($level, $source, $message, $sourceId, $userId, $context);
        } else {
            $this->writeToDatabase($level, $source, $message, $sourceId, $userId, $context);
        }
    }

    /**
     * Coerce a context value to an int, or null when it cannot be one
     */
    private function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Flatten CR/LF so one log call cannot write more than one line
     *
     * Without this, any consumer logging a username, a URL or an exception
     * message carrying user input can forge audit entries byte-identical in
     * shape to genuine ones — verified producing three lines from two calls,
     * the middle one entirely fabricated.
     */
    private function singleLine(string $value): string
    {
        return str_replace(["\r\n", "\r", "\n"], ['\r\n', '\r', '\n'], $value);
    }

    /**
     * Write to database, fallback to file on error
     *
     * @param array<string, mixed> $context
     */
    private function writeToDatabase(
        int $level,
        string $source,
        string $message,
        ?string $sourceId,
        int $userId,
        array $context
    ): bool {
        try {
            $eventType = self::LEVEL_NAMES[$level] ?? 'UNKNOWN';
            $contextJson = !empty($context) ? json_encode($context) : null;

            $this->db->insert($this->logTable, [
                'origdate' => date('Y-m-d H:i:s'),
                'user_id' => $userId,
                'rowtype' => 'log',
                'logsource' => $source,
                'logsourceid' => $sourceId,
                'eventtype' => $eventType,
                'eventdescription' => $message,
                // Was computed and then dropped on the floor, so structured
                // context vanished on the DB path while the file path kept it
                'context' => $contextJson,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Log the database error to file
            $this->writeToFile(
                self::LEVEL_WARNING,
                'Logger',
                "Database log failed: {$e->getMessage()}, falling back to file",
                null,
                $userId,
                []
            );

            // Write original message to file
            return $this->writeToFile($level, $source, $message, $sourceId, $userId, $context);
        }
    }

    /**
     * Write to file
     *
     * @param array<string, mixed> $context
     */
    private function writeToFile(
        int $level,
        string $source,
        string $message,
        ?string $sourceId,
        int $userId,
        array $context
    ): bool {
        $levelName = self::LEVEL_NAMES[$level] ?? 'UNKNOWN';
        $timestamp = date('Y-m-d H:i:s');
        $sourceIdPart = $sourceId ? ' [' . $this->singleLine($sourceId) . ']' : '';

        // json_encode already escapes newlines, so the context part is safe
        $contextPart = !empty($context) ? ' ' . json_encode($context) : '';

        $logLine = sprintf(
            "[%s] [%s] [%s]%s [user:%d] %s%s\n",
            $timestamp,
            $levelName,
            $this->singleLine($source),
            $sourceIdPart,
            $userId,
            $this->singleLine($message),
            $contextPart
        );

        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        return file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * Interpolate context values into message placeholders
     *
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_string($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        return strtr($message, $replace);
    }

    /**
     * Get current user ID from session
     */
    private function getUserId(): int
    {
        return (int) ($_SESSION['id'] ?? 0);
    }

    // Convenience methods using KallioMicro levels

    /**
     * Log a bypass/debug message (level 0)
     *
     * @param array<string, mixed> $context
     */
    public function bypass(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_BYPASS, $message, $context);
    }

    /**
     * Log a success message (level 1)
     *
     * @param array<string, mixed> $context
     */
    public function success(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_SUCCESS, $message, $context);
    }

    // PSR-3 compatible methods

    /**
     * @param array<string, mixed> $context
     */
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(self::ALERT, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    // Configuration methods

    /**
     * Force file-only logging mode
     */
    public function setFileOnly(bool $fileOnly = true): self
    {
        $this->useFileOnly = $fileOnly;
        return $this;
    }

    /**
     * Set custom log file path
     */
    public function setLogFile(string $path): self
    {
        $this->logFile = $path;
        return $this;
    }

    /**
     * Set minimum log level
     */
    public function setMinLevel(int $level): self
    {
        $this->minLevel = $level;
        return $this;
    }

    /**
     * Create a new logger instance for a specific channel
     */
    public function channel(string $channel): self
    {
        $logger = clone $this;
        $logger->channel = $channel;
        return $logger;
    }

    /**
     * Get the current channel name
     */
    public function getChannel(): string
    {
        return $this->channel;
    }
}
