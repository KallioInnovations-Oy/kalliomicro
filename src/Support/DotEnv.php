<?php

declare(strict_types=1);

namespace KallioMicro\Support;

use RuntimeException;

/**
 * DotEnv - Native .env file parser
 *
 * A minimal, zero-dependency .env file loader for PHP 8.1+.
 * Parses .env files and loads variables into $_ENV and putenv().
 *
 * Supported syntax:
 * - KEY=value
 * - KEY="quoted value"
 * - KEY='single quoted value'
 * - KEY=value # inline comments
 * - KEY="quoted value" # inline comments after a closing quote
 * - # full line comments
 * - Empty lines are ignored
 * - Multiline values with quotes
 *
 * Inline comments and multiline values coexist by locating the closing quote
 * rather than testing whether the line ends with one: a quoted value is
 * multiline only when its quote does not close on the same line, and anything
 * following the closing quote is discarded as a comment. An unterminated quote
 * at end of file raises — it previously swallowed every remaining line, so a
 * stray comment after a quoted value silently discarded the rest of the file
 * (APP_DEBUG and DB credentials included) with no error.
 *
 * Not supported: variable interpolation. `KEY="${OTHER}"` stores the literal
 * text `${OTHER}`.
 *
 * Values are always stored as strings ($_ENV/putenv are string-only).
 * Type coercion of the special values true/false/null/empty (with or
 * without parentheses, case-insensitive) is performed by the env()
 * helper in src/Support/helpers.php, not by this loader.
 */
class DotEnv
{
    private string $path;
    private bool $overwrite;

    /** @var array<string, string> Parsed variables */
    private array $variables = [];

    public function __construct(string $path, bool $overwrite = false)
    {
        $this->path = rtrim($path, DIRECTORY_SEPARATOR);
        $this->overwrite = $overwrite;
    }

    /**
     * Create a DotEnv instance for a directory
     */
    public static function create(string $path, bool $overwrite = false): self
    {
        return new self($path, $overwrite);
    }

    /**
     * Load .env file and set environment variables
     *
     * @throws \RuntimeException if .env file is not found or not readable
     */
    public function load(): self
    {
        $envFile = $this->path . DIRECTORY_SEPARATOR . '.env';

        if (!file_exists($envFile)) {
            throw new \RuntimeException("Environment file not found: {$envFile}");
        }

        if (!is_readable($envFile)) {
            throw new \RuntimeException("Environment file is not readable: {$envFile}");
        }

        $this->parse($envFile);
        $this->setEnvironmentVariables();

        return $this;
    }

    /**
     * Load .env file if it exists (no exception if missing)
     */
    public function safeLoad(): self
    {
        $envFile = $this->path . DIRECTORY_SEPARATOR . '.env';

        if (!file_exists($envFile) || !is_readable($envFile)) {
            return $this;
        }

        $this->parse($envFile);
        $this->setEnvironmentVariables();

        return $this;
    }

    /**
     * Parse the .env file contents
     */
    private function parse(string $file): void
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return;
        }

        $lines = explode("\n", $contents);
        $multilineKey = null;
        $multilineValue = '';
        $multilineQuote = null;

        foreach ($lines as $line) {
            // Handle multiline values
            if ($multilineKey !== null) {
                if ($multilineQuote !== null && str_ends_with(rtrim($line), $multilineQuote)) {
                    // End of multiline
                    $multilineValue .= "\n" . rtrim(rtrim($line), $multilineQuote);
                    $this->variables[$multilineKey] = $multilineValue;
                    $multilineKey = null;
                    $multilineValue = '';
                    $multilineQuote = null;
                } else {
                    $multilineValue .= "\n" . $line;
                }
                continue;
            }

            // Skip empty lines
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Skip comments
            if (str_starts_with($line, '#')) {
                continue;
            }

            // Parse KEY=value
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = substr($line, $pos + 1);

            // Skip invalid keys
            if ($key === '' || !$this->isValidKey($key)) {
                continue;
            }

            // Parse value
            $value = $this->parseValue($value, $multilineKey, $multilineValue, $multilineQuote, $key);

            if ($multilineKey === null) {
                $this->variables[$key] = $value;
            }
        }

        // A quote left open at end of file means every line after it was
        // absorbed into that value and is now missing. Failing loudly is the
        // only safe option: silently, the app boots with a truthy APP_DEBUG
        // and whatever database defaults happen to be in config/.
        if ($multilineKey !== null) {
            throw new RuntimeException(sprintf(
                'Unterminated %s quote in %s: the value of %s is never closed, '
                . 'so every later variable in the file was discarded.',
                $multilineQuote === '"' ? 'double' : 'single',
                $this->path . DIRECTORY_SEPARATOR . '.env',
                $multilineKey
            ));
        }
    }

    /**
     * Find the index of the quote that closes a quoted value, or null
     *
     * Backslash escapes are honoured inside double quotes only, matching
     * processEscapes().
     */
    private function findClosingQuote(string $value, string $quote): ?int
    {
        $length = strlen($value);

        for ($i = 1; $i < $length; $i++) {
            if ($quote === '"' && $value[$i] === '\\') {
                $i++;
                continue;
            }

            if ($value[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Check if a key name is valid
     */
    private function isValidKey(string $key): bool
    {
        // Allow alphanumeric, underscore, and optionally prefixed with export
        $key = preg_replace('/^export\s+/', '', $key);
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1;
    }

    /**
     * Parse and clean a value
     */
    private function parseValue(
        string $value,
        ?string &$multilineKey,
        string &$multilineValue,
        ?string &$multilineQuote,
        string $key
    ): string {
        $value = trim($value);

        // Empty value
        if ($value === '') {
            return '';
        }

        // Check for quoted values
        $firstChar = $value[0];

        if ($firstChar === '"' || $firstChar === "'") {
            // Locate the closing quote rather than testing whether the line
            // ends with one. `KEY="value"  # comment` ends with 't', so the
            // old test treated it as the start of a multiline value and
            // absorbed the rest of the file.
            $closing = $this->findClosingQuote($value, $firstChar);

            if ($closing !== null) {
                // Anything after the closing quote is an inline comment
                $value = substr($value, 1, $closing - 1);

                if ($firstChar === '"') {
                    // Process escape sequences in double quotes
                    $value = $this->processEscapes($value);
                }

                return $value;
            }

            // Quote does not close on this line — start of a multiline value
            $multilineKey = $key;
            $multilineQuote = $firstChar;
            $multilineValue = substr($value, 1);

            return '';
        }

        // Unquoted value - remove inline comments
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) {
            $value = trim(substr($value, 0, $commentPos));
        }

        return $value;
    }

    /**
     * Process escape sequences in double-quoted strings
     */
    private function processEscapes(string $value): string
    {
        $replacements = [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\"' => '"',
            '\\\\' => '\\',
        ];

        return strtr($value, $replacements);
    }

    /**
     * Set parsed variables in the environment
     */
    private function setEnvironmentVariables(): void
    {
        foreach ($this->variables as $key => $value) {
            // Skip if already set and not overwriting
            if (!$this->overwrite && (isset($_ENV[$key]) || getenv($key) !== false)) {
                continue;
            }

            // Set in $_ENV
            $_ENV[$key] = $value;

            // Set in putenv (some systems need this)
            putenv("{$key}={$value}");
        }
    }

    /**
     * Get all parsed variables
     *
     * @return array<string, string|bool|null>
     */
    public function all(): array
    {
        return $this->variables;
    }

    /**
     * Get a specific variable value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->variables[$key] ?? $default;
    }

    /**
     * Check if a variable was defined
     */
    public function has(string $key): bool
    {
        return isset($this->variables[$key]);
    }

    /**
     * Require specific variables to be defined
     *
     * @param array<string> $keys
     * @throws \RuntimeException if any required variable is missing
     */
    public function required(array $keys): self
    {
        $missing = [];

        foreach ($keys as $key) {
            if (!$this->has($key) && getenv($key) === false) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Required environment variables are missing: ' . implode(', ', $missing)
            );
        }

        return $this;
    }
}
