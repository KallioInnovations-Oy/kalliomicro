<?php

declare(strict_types=1);

namespace KallioMicro\Console;

/**
 * Command - Base class for console commands
 */
abstract class Command
{
    protected Console $console;

    /** @var string Command name */
    protected string $name = '';

    /** @var string Command description */
    protected string $description = '';

    /** @var string Usage example */
    protected string $usage = '';

    /** @var array<string, string> Argument descriptions */
    protected array $arguments = [];

    /** @var array<string, string> Option descriptions */
    protected array $options = [];

    public function __construct(Console $console)
    {
        $this->console = $console;
        $this->configure();
    }

    /**
     * Configure the command (override to set name, description, etc.)
     */
    protected function configure(): void
    {
        // Override in subclass
    }

    /**
     * Execute the command
     */
    abstract public function handle(Input $input): int;

    /**
     * Get command name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get command description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get usage string
     */
    public function getUsage(): string
    {
        return $this->usage;
    }

    /**
     * Get argument descriptions
     *
     * @return array<string, string>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * Get option descriptions
     *
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    // Output shortcuts

    protected function line(string $message = ''): void
    {
        $this->console->line($message);
    }

    protected function info(string $message): void
    {
        $this->console->info($message);
    }

    protected function success(string $message): void
    {
        $this->console->success($message);
    }

    protected function warning(string $message): void
    {
        $this->console->warning($message);
    }

    protected function error(string $message): void
    {
        $this->console->error($message);
    }

    protected function comment(string $message): void
    {
        $this->console->comment($message);
    }

    protected function table(array $headers, array $rows): void
    {
        $this->console->table($headers, $rows);
    }

    protected function ask(string $question, string $default = ''): string
    {
        return $this->console->ask($question, $default);
    }

    protected function confirm(string $question, bool $default = false): bool
    {
        return $this->console->confirm($question, $default);
    }

    protected function progressBar(int $current, int $total, int $width = 50): void
    {
        $this->console->progressBar($current, $total, $width);
    }

    /**
     * Get the application container
     */
    protected function app(): \KallioMicro\Core\Application
    {
        return $this->console->getApp();
    }
}
