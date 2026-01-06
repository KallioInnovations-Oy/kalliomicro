<?php

declare(strict_types=1);

namespace KallioMicro\Console\Commands;

use KallioMicro\Console\Command;
use KallioMicro\Console\Input;

/**
 * TaskCommand - Base class for task commands
 *
 * Provides common functionality for scheduled tasks
 */
abstract class TaskCommand extends Command
{
    /** @var string The cron schedule for this task */
    protected string $schedule = '';

    /**
     * Get the cron schedule
     */
    public function getSchedule(): string
    {
        return $this->schedule;
    }

    /**
     * Log task start
     */
    protected function logStart(): void
    {
        $this->info("[" . date('Y-m-d H:i:s') . "] Starting: {$this->name}");
    }

    /**
     * Log task completion
     */
    protected function logComplete(string $message = ''): void
    {
        $output = "[" . date('Y-m-d H:i:s') . "] Completed: {$this->name}";
        if ($message !== '') {
            $output .= " - {$message}";
        }
        $this->success($output);
    }

    /**
     * Log task failure
     */
    protected function logFailed(string $message): void
    {
        $this->error("[" . date('Y-m-d H:i:s') . "] Failed: {$this->name} - {$message}");
    }
}
