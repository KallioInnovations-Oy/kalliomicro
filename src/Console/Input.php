<?php

declare(strict_types=1);

namespace KallioMicro\Console;

/**
 * Input - Parsed command line arguments and options
 */
class Input
{
    /** @var array<int, string> */
    private array $arguments;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function __construct(array $arguments = [], array $options = [])
    {
        $this->arguments = $arguments;
        $this->options = $options;
    }

    /**
     * Get all arguments
     *
     * @return array<int, string>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * Get argument by index
     */
    public function getArgument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    /**
     * Get first argument
     */
    public function first(?string $default = null): ?string
    {
        return $this->getArgument(0, $default);
    }

    /**
     * Get all options
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Get option value
     */
    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * Check if option exists
     */
    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /**
     * Check if an argument matches a value
     */
    public function is(int $index, string $value): bool
    {
        return ($this->arguments[$index] ?? null) === $value;
    }

    /**
     * Get argument count
     */
    public function count(): int
    {
        return count($this->arguments);
    }

    /**
     * Check if there are no arguments
     */
    public function isEmpty(): bool
    {
        return empty($this->arguments);
    }
}
