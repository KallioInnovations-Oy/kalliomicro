<?php

declare(strict_types=1);

namespace KallioMicro\Core;

use ArrayAccess;
use RuntimeException;

/**
 * Config - Configuration management with dot notation support
 *
 * Loads configuration files from the config directory and provides
 * a simple API to access values using dot notation.
 */
class Config implements ArrayAccess
{
    /** @var array<string, mixed> */
    private array $items = [];

    private string $configPath;

    public function __construct(string $configPath)
    {
        $this->configPath = rtrim($configPath, '/');
        $this->loadConfigFiles();
    }

    /**
     * Load all PHP config files from the config directory
     */
    private function loadConfigFiles(): void
    {
        if (!is_dir($this->configPath)) {
            return;
        }

        $files = glob($this->configPath . '/*.php');

        foreach ($files as $file) {
            $key = basename($file, '.php');
            $this->items[$key] = require $file;
        }
    }

    /**
     * Get a configuration value using dot notation
     *
     * @param string $key The key in dot notation (e.g., 'app.debug')
     * @param mixed $default Default value if key doesn't exist
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->items;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Set a configuration value using dot notation
     */
    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $items = &$this->items;

        foreach ($keys as $i => $segment) {
            if (count($keys) === 1) {
                break;
            }

            unset($keys[$i]);

            if (!isset($items[$segment]) || !is_array($items[$segment])) {
                $items[$segment] = [];
            }

            $items = &$items[$segment];
        }

        $items[array_shift($keys)] = $value;
    }

    /**
     * Check if a configuration key exists
     */
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->items;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    /**
     * Get all configuration items
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Get an entire configuration group
     *
     * @return array<string, mixed>
     */
    public function group(string $key): array
    {
        $value = $this->get($key, []);
        return is_array($value) ? $value : [];
    }

    // ArrayAccess implementation

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $keys = explode('.', (string) $offset);
        $items = &$this->items;

        while (count($keys) > 1) {
            $segment = array_shift($keys);
            if (!isset($items[$segment]) || !is_array($items[$segment])) {
                return;
            }
            $items = &$items[$segment];
        }

        unset($items[array_shift($keys)]);
    }
}
