<?php

declare(strict_types=1);

namespace KallioMicro\Console\Commands;

use KallioMicro\Console\Command;
use KallioMicro\Console\Input;

/**
 * ListCommand - List all available commands
 */
class ListCommand extends Command
{
    protected string $name = 'list';
    protected string $description = 'List all available commands';

    public function handle(Input $input): int
    {
        $this->line('');
        $this->info('KallioMicro Console');
        $this->line('');
        $this->info('Available commands:');
        $this->line('');

        $commands = $this->console->getCommands();
        ksort($commands);

        // Group commands by namespace
        $grouped = [];
        foreach ($commands as $name => $command) {
            $parts = explode(':', $name);
            $namespace = count($parts) > 1 ? $parts[0] : '';
            $grouped[$namespace][$name] = $command;
        }

        // Sort namespaces
        ksort($grouped);

        foreach ($grouped as $namespace => $commands) {
            if ($namespace !== '') {
                $this->info(" {$namespace}");
            }

            foreach ($commands as $name => $command) {
                $padding = str_repeat(' ', 25 - strlen($name));
                $this->line("  {$name}{$padding}{$command->getDescription()}");
            }

            $this->line('');
        }

        return 0;
    }
}
