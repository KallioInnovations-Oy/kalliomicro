<?php

declare(strict_types=1);

namespace KallioMicro\Core;

use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use InvalidArgumentException;
use RuntimeException;

/**
 * Container - A simple but powerful dependency injection container
 *
 * Supports singleton binding, automatic resolution, and interface aliasing.
 * PSR-11 compatible (has/get methods) without requiring the interface package.
 */
class Container
{
    /** @var array<string, Closure|object> */
    protected array $bindings = [];

    /** @var array<string, object> */
    protected array $instances = [];

    /** @var array<string, string> */
    protected array $aliases = [];

    /** @var array<string, true> */
    protected array $singletons = [];

    /**
     * Bind a service to the container
     */
    public function bind(string $abstract, Closure|string|null $concrete = null): void
    {
        $concrete ??= $abstract;

        if (is_string($concrete)) {
            $concrete = fn() => $this->build($concrete);
        }

        $this->bindings[$abstract] = $concrete;
    }

    /**
     * Register a singleton binding
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete);
        $this->singletons[$abstract] = true;
    }

    /**
     * Register an existing instance as a singleton
     */
    public function instance(string $abstract, object $instance): object
    {
        $this->instances[$abstract] = $instance;
        return $instance;
    }

    /**
     * Create an alias for a binding
     */
    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    /**
     * Check if a binding exists
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id])
            || isset($this->instances[$id])
            || isset($this->aliases[$id]);
    }

    /**
     * Resolve a service from the container
     */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * Resolve a service from the container
     *
     * @template T
     * @param class-string<T>|string $abstract
     * @param array<string, mixed> $parameters
     * @return T|mixed
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        // Check for alias
        $abstract = $this->getAlias($abstract);

        // Return existing singleton instance
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Get the concrete resolver
        $concrete = $this->bindings[$abstract] ?? null;

        $object = $concrete instanceof Closure
            ? $concrete($this, $parameters)
            : $this->build($concrete ?? $abstract, $parameters);

        // Store singleton
        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Build a class instance with automatic dependency injection
     *
     * @param class-string $concrete
     * @param array<string, mixed> $parameters
     */
    public function build(string $concrete, array $parameters = []): object
    {
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new RuntimeException("Class [{$concrete}] does not exist.", 0, $e);
        }

        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Class [{$concrete}] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        $dependencies = $this->resolveDependencies(
            $constructor->getParameters(),
            $parameters
        );

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Resolve constructor dependencies
     *
     * @param ReflectionParameter[] $dependencies
     * @param array<string, mixed> $parameters
     * @return array<mixed>
     */
    protected function resolveDependencies(array $dependencies, array $parameters): array
    {
        $resolved = [];

        foreach ($dependencies as $dependency) {
            $name = $dependency->getName();

            // Use provided parameter if available
            if (array_key_exists($name, $parameters)) {
                $resolved[] = $parameters[$name];
                continue;
            }

            $type = $dependency->getType();

            // Handle typed parameters
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $resolved[] = $this->make($type->getName());
                continue;
            }

            // Use default value if available
            if ($dependency->isDefaultValueAvailable()) {
                $resolved[] = $dependency->getDefaultValue();
                continue;
            }

            // Handle nullable types
            if ($type !== null && $type->allowsNull()) {
                $resolved[] = null;
                continue;
            }

            throw new RuntimeException(
                "Unable to resolve dependency [{$name}] in class"
            );
        }

        return $resolved;
    }

    /**
     * Get the alias for an abstract if it exists
     */
    protected function getAlias(string $abstract): string
    {
        return isset($this->aliases[$abstract])
            ? $this->getAlias($this->aliases[$abstract])
            : $abstract;
    }

    /**
     * Call a method with dependency injection
     *
     * @param callable|array{object|class-string, string} $callback
     * @param array<string, mixed> $parameters
     */
    public function call(callable|array $callback, array $parameters = []): mixed
    {
        if (is_array($callback)) {
            [$class, $method] = $callback;

            if (is_string($class)) {
                $class = $this->make($class);
            }

            $reflector = new ReflectionClass($class);
            $methodReflector = $reflector->getMethod($method);
            $dependencies = $this->resolveDependencies(
                $methodReflector->getParameters(),
                $parameters
            );

            return $methodReflector->invokeArgs($class, $dependencies);
        }

        // Closures get the same by-name resolution as methods: only declared
        // parameters are passed, so a zero-arg closure handler doesn't fatal
        // on "Unknown named parameter" when the router supplies 'request'.
        $reflector = new \ReflectionFunction($callback(...));
        $dependencies = $this->resolveDependencies($reflector->getParameters(), $parameters);

        return $callback(...$dependencies);
    }

    /**
     * Flush all bindings and instances
     */
    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
        $this->singletons = [];
    }
}
