<?php

declare(strict_types=1);

namespace KallioMicro\Console\Commands;

use KallioMicro\Console\Command;
use KallioMicro\Console\Input;

/**
 * HelpCommand - Show help for a command
 */
class HelpCommand extends Command
{
    protected string $name = 'help';
    protected string $description = 'Show help for a command';
    protected string $usage = '<command>';
    protected array $arguments = [
        'command' => 'The command to show help for',
    ];

    public function handle(Input $input): int
    {
        $commandName = $input->first();

        if ($commandName === null) {
            $this->line('');
            $this->info('Usage:');
            $this->line('  help <command>');
            $this->line('');
            $this->line("Run 'php console list' to see all available commands.");
            $this->line('');
            return 0;
        }

        $commands = $this->console->getCommands();

        if (!isset($commands[$commandName])) {
            $this->error("Command '{$commandName}' not found.");
            return 1;
        }

        $command = $commands[$commandName];

        $this->line('');
        $this->info('Description:');
        $this->line('  ' . $command->getDescription());
        $this->line('');

        $this->info('Usage:');
        $this->line('  ' . $commandName . ' ' . $command->getUsage());
        $this->line('');

        $arguments = $command->getArguments();
        if (!empty($arguments)) {
            $this->info('Arguments:');
            foreach ($arguments as $name => $description) {
                $padding = str_repeat(' ', max(1, 20 - strlen($name)));
                $this->line("  {$name}{$padding}{$description}");
            }
            $this->line('');
        }

        $options = $command->getOptions();
        if (!empty($options)) {
            $this->info('Options:');
            foreach ($options as $name => $description) {
                $padding = str_repeat(' ', max(1, 18 - strlen($name)));
                $this->line("  --{$name}{$padding}{$description}");
            }
            $this->line('');
        }

        return 0;
    }
}
