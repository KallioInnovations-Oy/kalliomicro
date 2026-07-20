<?php

declare(strict_types=1);

namespace KallioMicro\Routing;

use Closure;
use InvalidArgumentException;

/**
 * Route - Represents a single route definition
 *
 * Handles URL pattern matching with parameter extraction,
 * middleware assignment, and URL generation.
 */
class Route
{
    private string $method;
    private string $path;
    private string $pattern;

    /** @var array|Closure|string */
    private $handler;

    /** @var array<string, string> */
    private array $parameterPatterns = [];

    /** @var array<string, string> */
    private array $defaults = [];

    /** @var array<int, Closure|string> */
    private array $middleware = [];

    private ?string $name = null;

    /**
     * @param array|Closure|string $handler
     */
    public function __construct(string $method, string $path, $handler)
    {
        $this->method = $method;
        $this->path = $path;
        $this->handler = $handler;
        $this->pattern = $this->compilePattern($path);
    }

    /**
     * Compile route path to regex pattern
     */
    private function compilePattern(string $path): string
    {
        // Split the path on {param} / {param?} placeholders, keeping the
        // placeholders as delimiters, so literal text can be preg_quote()d and
        // only the placeholders become regex. Escaping just the slashes let
        // route metacharacters through: "/files/report.pdf" also matched
        // "/files/reportXpdf", and a path with an unbalanced "(" compiled to an
        // invalid pattern — permanently unmatchable, and preg_match() warned on
        // every single request because every dispatch re-tests every route.
        $parts = preg_split(
            '/(\{[a-zA-Z_][a-zA-Z0-9_]*\??\})/',
            $path,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        $pattern = '';

        foreach ($parts as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?\}$/', $part, $matches) !== 1) {
                $pattern .= preg_quote($part, '/');
                continue;
            }

            $param = $matches[1];
            // Use custom pattern if defined, otherwise default to non-slash characters
            $paramPattern = $this->parameterPatterns[$param] ?? '[^\/]+';

            // "{param?}" makes the value optional, not its separator slash
            $pattern .= isset($matches[2])
                ? "(?:(?P<{$param}>{$paramPattern}))?"
                : "(?P<{$param}>{$paramPattern})";
        }

        return '/^' . $pattern . '\/?$/';
    }

    /**
     * Check if this route matches the given method and path
     */
    public function matches(string $method, string $path): bool
    {
        if ($this->method !== $method) {
            return false;
        }

        return (bool) preg_match($this->pattern, $path);
    }

    /**
     * Check if this route matches the given path regardless of HTTP method
     * (used to distinguish 405 from 404)
     */
    public function matchesPath(string $path): bool
    {
        return (bool) preg_match($this->pattern, $path);
    }

    /**
     * Extract route parameters from the path
     *
     * @return array<string, string>
     */
    public function extractParams(string $path): array
    {
        $matches = [];
        preg_match($this->pattern, $path, $matches);

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key) && $value !== '') {
                // Captured groups come straight off the raw request path, so a
                // percent-encoded segment reached handlers as the literal
                // "a%2Fb" instead of the "a/b" the client actually sent —
                // every lookup by that parameter missed.
                $params[$key] = rawurldecode($value);
            }
        }

        // Apply defaults for missing optional parameters (author-supplied
        // literals, never encoded — decoding them would mangle a legitimate %)
        foreach ($this->defaults as $key => $default) {
            if (!isset($params[$key])) {
                $params[$key] = $default;
            }
        }

        return $params;
    }

    /**
     * Generate URL for this route with given parameters
     *
     * @param array<string, string> $params
     * @throws InvalidArgumentException when a required {param} has no value
     */
    public function generateUrl(array $params = []): string
    {
        $url = $this->path;

        foreach ($params as $key => $value) {
            // Values are single path segments and must be encoded as such:
            // unencoded, an id of "1?x=2#y" grafted a query string and fragment
            // onto the generated URL, and "a/b" invented an extra path segment,
            // so a link could point somewhere the route never declared.
            $url = str_replace(
                ["{{$key}}", "{{$key}?}"],
                rawurlencode((string) $value),
                $url
            );
        }

        // Remove unfilled optional parameters
        $url = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\?\}/', '', $url);

        // A required parameter left unfilled used to ship the literal
        // "/users/{id}" into an href — a dead link discovered by a user, not by
        // the developer. Report the boundary at generation time instead.
        if (preg_match('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $url, $missing) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Route [%s] requires parameter [%s]; given: %s',
                $this->path,
                $missing[1],
                $params === [] ? 'none' : implode(', ', array_keys($params))
            ));
        }

        // Clean up double slashes
        $url = preg_replace('/\/+/', '/', $url);

        return rtrim($url, '/') ?: '/';
    }

    // Fluent configuration methods

    /**
     * Set a name for URL generation
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Add middleware to this route
     *
     * Accepts a Closure(Request, Closure): Response, or a class-string of a
     * MiddlewareInterface implementation — resolved through the container at
     * dispatch time, so constructor dependencies auto-wire. Parameterized
     * middleware (variadic roles, custom $except lists) still use the closure
     * form, since the container cannot guess those arguments.
     */
    public function middleware(Closure|string $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Set regex constraint for a parameter
     */
    public function where(string $param, string $pattern): self
    {
        $this->parameterPatterns[$param] = $pattern;
        // Recompile pattern with new constraint
        $this->pattern = $this->compilePattern($this->path);
        return $this;
    }

    /**
     * Set multiple regex constraints
     *
     * @param array<string, string> $patterns
     */
    public function whereArray(array $patterns): self
    {
        foreach ($patterns as $param => $pattern) {
            $this->parameterPatterns[$param] = $pattern;
        }
        $this->pattern = $this->compilePattern($this->path);
        return $this;
    }

    /**
     * Constrain parameter to numeric values
     */
    public function whereNumber(string $param): self
    {
        return $this->where($param, '[0-9]+');
    }

    /**
     * Constrain parameter to alphabetic values
     */
    public function whereAlpha(string $param): self
    {
        return $this->where($param, '[a-zA-Z]+');
    }

    /**
     * Constrain parameter to alphanumeric values
     */
    public function whereAlphaNumeric(string $param): self
    {
        return $this->where($param, '[a-zA-Z0-9]+');
    }

    /**
     * Constrain parameter to UUID format
     */
    public function whereUuid(string $param): self
    {
        return $this->where($param, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    }

    /**
     * Set default value for optional parameter
     */
    public function default(string $param, string $value): self
    {
        $this->defaults[$param] = $value;
        return $this;
    }

    // Getters

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return array|Closure|string
     */
    public function getHandler()
    {
        return $this->handler;
    }

    /**
     * @return array<int, Closure|string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}
