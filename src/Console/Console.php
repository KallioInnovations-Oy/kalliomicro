<?php

declare(strict_types=1);

namespace KallioMicro\Console;

use KallioMicro\Core\Application;
use KallioMicro\Support\Logger;
use KallioMicro\Console\Commands\ListCommand;
use KallioMicro\Console\Commands\HelpCommand;
use KallioMicro\Console\Commands\ScheduleRunCommand;

/**
 * Console - CLI application for running commands and tasks
 *
 * Features:
 * - Command registration and discovery
 * - Cron-based task scheduling
 * - Argument and option parsing
 * - Colored output support
 * - Progress indicators
 */
class Console
{
    private Application $app;
    private ?Logger $logger;

    /** @var array<string, Command> */
    private array $commands = [];

    /** @var array<string, array{command: string, schedule: string}> */
    private array $scheduledTasks = [];

    private bool $useColors = true;

    public function __construct(Application $app, ?Logger $logger = null)
    {
        $this->app = $app;
        $this->logger = $logger;
        $this->useColors = $this->supportsColors();

        $this->registerBuiltinCommands();
    }

    /**
     * Register built-in commands
     */
    private function registerBuiltinCommands(): void
    {
        $this->register('list', new ListCommand($this));
        $this->register('help', new HelpCommand($this));
        $this->register('schedule:run', new ScheduleRunCommand($this));
    }

    /**
     * Register a command
     */
    public function register(string $name, Command $command): self
    {
        $this->commands[$name] = $command;
        return $this;
    }

    /**
     * Register a command class
     */
    public function registerClass(string $commandClass): self
    {
        if (!is_subclass_of($commandClass, Command::class)) {
            throw new \InvalidArgumentException("{$commandClass} must extend Command");
        }

        /** @var Command $command */
        $command = new $commandClass($this);
        $this->commands[$command->getName()] = $command;

        return $this;
    }

    /**
     * Schedule a command to run on a cron schedule
     *
     * Policy note: schedule:run provides NO overlap protection — a task that
     * runs longer than its interval gets a second concurrent instance on the
     * next tick. Slow or non-idempotent tasks must take their own lock (e.g.
     * MySQL GET_LOCK held by the DB connection, which auto-releases if the
     * process dies) at the start of handle().
     */
    public function schedule(string $commandName, string $cronExpression): self
    {
        $this->scheduledTasks[$commandName] = [
            'command' => $commandName,
            'schedule' => $cronExpression,
        ];

        return $this;
    }

    /**
     * Get all scheduled tasks
     *
     * @return array<string, array{command: string, schedule: string}>
     */
    public function getScheduledTasks(): array
    {
        return $this->scheduledTasks;
    }

    /**
     * Run the console application
     *
     * @param array<string> $argv
     */
    public function run(array $argv = []): int
    {
        if (empty($argv)) {
            $argv = $_SERVER['argv'] ?? ['console'];
        }

        // Remove script name
        array_shift($argv);

        // Get command name
        $commandName = array_shift($argv) ?? 'list';

        // Parse arguments
        $input = $this->parseArguments($argv);

        // Find and run command
        return $this->execute($commandName, $input);
    }

    /**
     * Execute a command by name
     */
    public function execute(string $commandName, Input $input): int
    {
        if (!isset($this->commands[$commandName])) {
            $this->error("Command '{$commandName}' not found.");
            $this->line('');
            $this->line("Run 'php console list' to see available commands.");
            return 1;
        }

        try {
            $command = $this->commands[$commandName];

            // Check for help flag
            if ($input->hasOption('help') || $input->hasOption('h')) {
                $this->showCommandHelp($command);
                return 0;
            }

            $result = $command->handle($input);

            return is_int($result) ? $result : 0;

        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            if ($input->hasOption('verbose') || $input->hasOption('v')) {
                $this->line('');
                $this->line($e->getTraceAsString());
            }

            $this->log('error', "Command '{$commandName}' failed: " . $e->getMessage());

            return 1;
        }
    }

    /**
     * Parse command line arguments
     *
     * @param array<string> $argv
     */
    private function parseArguments(array $argv): Input
    {
        $arguments = [];
        $options = [];

        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                // Long option: --name=value or --flag
                $arg = substr($arg, 2);
                if (str_contains($arg, '=')) {
                    [$name, $value] = explode('=', $arg, 2);
                    $options[$name] = $value;
                } else {
                    $options[$arg] = true;
                }
            } elseif (str_starts_with($arg, '-')) {
                // Short option: -v or -n value
                $name = substr($arg, 1);
                $options[$name] = true;
            } else {
                // Positional argument
                $arguments[] = $arg;
            }
        }

        return new Input($arguments, $options);
    }

    /**
     * Show help for a command
     */
    private function showCommandHelp(Command $command): void
    {
        $this->line('');
        $this->info("Description:");
        $this->line("  " . $command->getDescription());
        $this->line('');

        $this->info("Usage:");
        $this->line("  " . $command->getName() . " " . $command->getUsage());
        $this->line('');

        $arguments = $command->getArguments();
        if (!empty($arguments)) {
            $this->info("Arguments:");
            foreach ($arguments as $name => $description) {
                $this->line("  " . str_pad($name, 20) . $description);
            }
            $this->line('');
        }

        $options = $command->getOptions();
        if (!empty($options)) {
            $this->info("Options:");
            foreach ($options as $name => $description) {
                $this->line("  --" . str_pad($name, 18) . $description);
            }
            $this->line('');
        }
    }

    /**
     * Get all registered commands
     *
     * @return array<string, Command>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Get the application instance
     */
    public function getApp(): Application
    {
        return $this->app;
    }

    // Output methods

    /**
     * Write a line to stdout
     */
    public function line(string $message = ''): void
    {
        echo $message . PHP_EOL;
    }

    /**
     * Write an info message (blue)
     */
    public function info(string $message): void
    {
        $this->line($this->colorize($message, 'blue'));
    }

    /**
     * Write a success message (green)
     */
    public function success(string $message): void
    {
        $this->line($this->colorize($message, 'green'));
    }

    /**
     * Write a warning message (yellow)
     */
    public function warning(string $message): void
    {
        $this->line($this->colorize($message, 'yellow'));
    }

    /**
     * Write an error message (red)
     */
    public function error(string $message): void
    {
        fwrite(STDERR, $this->colorize($message, 'red') . PHP_EOL);
    }

    /**
     * Write a comment (gray)
     */
    public function comment(string $message): void
    {
        $this->line($this->colorize($message, 'gray'));
    }

    /**
     * Ask for user input
     */
    public function ask(string $question, string $default = ''): string
    {
        $defaultHint = $default !== '' ? " [{$default}]" : '';
        echo $this->colorize($question . $defaultHint . ': ', 'cyan');

        $handle = fopen('php://stdin', 'r');
        $input = trim(fgets($handle) ?: '');
        fclose($handle);

        return $input !== '' ? $input : $default;
    }

    /**
     * Ask for confirmation
     */
    public function confirm(string $question, bool $default = false): bool
    {
        $defaultHint = $default ? '[Y/n]' : '[y/N]';
        echo $this->colorize("{$question} {$defaultHint}: ", 'cyan');

        $handle = fopen('php://stdin', 'r');
        $input = strtolower(trim(fgets($handle) ?: ''));
        fclose($handle);

        if ($input === '') {
            return $default;
        }

        return in_array($input, ['y', 'yes'], true);
    }

    /**
     * Display a progress bar
     */
    public function progressBar(int $current, int $total, int $width = 50): void
    {
        $percent = $total > 0 ? round(($current / $total) * 100) : 0;
        $filled = (int) round(($percent / 100) * $width);
        $empty = $width - $filled;

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);

        echo "\r[{$bar}] {$percent}% ({$current}/{$total})";

        if ($current >= $total) {
            echo PHP_EOL;
        }
    }

    /**
     * Display a table
     *
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        // Calculate column widths
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
            }
        }

        // Build separator
        $separator = '+';
        foreach ($widths as $width) {
            $separator .= str_repeat('-', $width + 2) . '+';
        }

        // Print table
        $this->line($separator);

        // Headers
        $headerLine = '|';
        foreach ($headers as $i => $header) {
            $headerLine .= ' ' . str_pad($header, $widths[$i]) . ' |';
        }
        $this->line($this->colorize($headerLine, 'cyan'));
        $this->line($separator);

        // Rows
        foreach ($rows as $row) {
            $rowLine = '|';
            foreach ($row as $i => $cell) {
                $rowLine .= ' ' . str_pad((string) $cell, $widths[$i]) . ' |';
            }
            $this->line($rowLine);
        }

        $this->line($separator);
    }

    /**
     * Colorize text for terminal output
     */
    private function colorize(string $text, string $color): string
    {
        if (!$this->useColors) {
            return $text;
        }

        $codes = [
            'black' => '30',
            'red' => '31',
            'green' => '32',
            'yellow' => '33',
            'blue' => '34',
            'magenta' => '35',
            'cyan' => '36',
            'white' => '37',
            'gray' => '90',
        ];

        $code = $codes[$color] ?? '37';

        return "\033[{$code}m{$text}\033[0m";
    }

    /**
     * Check if terminal supports colors
     */
    private function supportsColors(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return getenv('ANSICON') !== false
                || getenv('ConEmuANSI') === 'ON'
                || getenv('TERM') === 'xterm';
        }

        return function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }

    /**
     * Log a message
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        $context['source'] = 'Console';

        match ($level) {
            'error' => $this->logger->error($message, $context),
            'warning' => $this->logger->warning($message, $context),
            'success' => $this->logger->success($message, $context),
            default => $this->logger->info($message, $context),
        };
    }
}
