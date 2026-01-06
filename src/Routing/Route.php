<?php

declare(strict_types=1);

namespace KallioMicro\Routing;

use Closure;

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

    /** @var Closure[] */
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
        // Escape forward slashes
        $pattern = preg_replace('/\//', '\\/', $path);

        // Convert {param} to named capture groups
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function ($matches) {
                $param = $matches[1];
                // Use custom pattern if defined, otherwise default to non-slash characters
                $paramPattern = $this->parameterPatterns[$param] ?? '[^\/]+';
                return "(?P<{$param}>{$paramPattern})";
            },
            $pattern
        );

        // Convert {param?} to optional named capture groups
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\?\}/',
            function ($matches) {
                $param = $matches[1];
                $paramPattern = $this->parameterPatterns[$param] ?? '[^\/]+';
                return "(?:(?P<{$param}>{$paramPattern}))?";
            },
            $pattern
        );

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
                $params[$key] = $value;
            }
        }

        // Apply defaults for missing optional parameters
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
     */
    public function generateUrl(array $params = []): string
    {
        $url = $this->path;

        foreach ($params as $key => $value) {
            $url = str_replace("{{$key}}", (string) $value, $url);
            $url = str_replace("{{$key}?}", (string) $value, $url);
        }

        // Remove unfilled optional parameters
        $url = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\?\}/', '', $url);

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
     */
    public function middleware(Closure $middleware): self
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
     * @return Closure[]
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}
