<?php

declare(strict_types=1);

namespace KallioMicro\Console\Commands;

use KallioMicro\Console\Command;
use KallioMicro\Console\Input;

/**
 * ScheduleRunCommand - Run scheduled tasks based on cron expressions
 *
 * This command should be called every minute by cron:
 * * * * * * php /path/to/console schedule:run >> /dev/null 2>&1
 */
class ScheduleRunCommand extends Command
{
    protected string $name = 'schedule:run';
    protected string $description = 'Run scheduled tasks that are due';
    protected array $options = [
        'list' => 'Show all scheduled tasks without running them',
        'force' => 'Force run all scheduled tasks regardless of schedule',
    ];

    public function handle(Input $input): int
    {
        $tasks = $this->console->getScheduledTasks();

        if (empty($tasks)) {
            $this->comment('No scheduled tasks registered.');
            return 0;
        }

        // List mode
        if ($input->hasOption('list')) {
            return $this->listScheduledTasks($tasks);
        }

        // Run due tasks
        $currentTime = new \DateTime();
        $tasksRun = 0;
        $tasksFailed = 0;
        $forceRun = $input->hasOption('force');

        foreach ($tasks as $name => $task) {
            $isDue = $forceRun || $this->isDue($task['schedule'], $currentTime);

            if ($isDue) {
                $this->info("Running: {$name}");

                $startTime = microtime(true);
                $result = $this->console->execute($name, new Input());
                $duration = round(microtime(true) - $startTime, 2);

                if ($result === 0) {
                    $this->success("  Completed in {$duration}s");
                    $tasksRun++;
                } else {
                    $this->error("  Failed (exit code: {$result})");
                    $tasksFailed++;
                }
            }
        }

        if ($tasksRun === 0 && $tasksFailed === 0) {
            $this->comment('No scheduled tasks are due.');
        } else {
            $this->line('');
            $this->info("Tasks run: {$tasksRun}, Failed: {$tasksFailed}");
        }

        return $tasksFailed > 0 ? 1 : 0;
    }

    /**
     * List all scheduled tasks
     *
     * @param array<string, array{command: string, schedule: string}> $tasks
     */
    private function listScheduledTasks(array $tasks): int
    {
        $this->line('');
        $this->info('Scheduled Tasks:');
        $this->line('');

        $rows = [];
        $currentTime = new \DateTime();

        foreach ($tasks as $name => $task) {
            $isDue = $this->isDue($task['schedule'], $currentTime) ? 'Yes' : 'No';
            $nextRun = $this->getNextRunTime($task['schedule']);

            $rows[] = [$name, $task['schedule'], $isDue, $nextRun];
        }

        $this->table(['Command', 'Schedule', 'Due Now', 'Next Run'], $rows);

        return 0;
    }

    /**
     * Check if a cron expression is due
     */
    private function isDue(string $expression, \DateTime $currentTime): bool
    {
        // Parse cron expression: minute hour day month weekday
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            return false;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        return $this->matchesField($minute, (int) $currentTime->format('i'))
            && $this->matchesField($hour, (int) $currentTime->format('G'))
            && $this->matchesField($day, (int) $currentTime->format('j'))
            && $this->matchesField($month, (int) $currentTime->format('n'))
            && $this->matchesField($weekday, (int) $currentTime->format('w'));
    }

    /**
     * Check if a cron field matches a value
     */
    private function matchesField(string $field, int $value): bool
    {
        // Wildcard
        if ($field === '*') {
            return true;
        }

        // Exact match
        if (ctype_digit($field)) {
            return (int) $field === $value;
        }

        // Range: 1-5
        if (str_contains($field, '-')) {
            [$start, $end] = explode('-', $field, 2);
            return $value >= (int) $start && $value <= (int) $end;
        }

        // List: 1,3,5
        if (str_contains($field, ',')) {
            $values = array_map('intval', explode(',', $field));
            return in_array($value, $values, true);
        }

        // Step: */5 or 0-30/5
        if (str_contains($field, '/')) {
            [$range, $step] = explode('/', $field, 2);
            $step = (int) $step;

            if ($range === '*') {
                return $value % $step === 0;
            }

            if (str_contains($range, '-')) {
                [$start, $end] = explode('-', $range, 2);
                if ($value < (int) $start || $value > (int) $end) {
                    return false;
                }
                return ($value - (int) $start) % $step === 0;
            }
        }

        return false;
    }

    /**
     * Get human-readable next run time
     */
    private function getNextRunTime(string $expression): string
    {
        // Simple implementation - find next occurrence
        $now = new \DateTime();

        for ($i = 0; $i < 1440; $i++) { // Check next 24 hours (1440 minutes)
            $checkTime = clone $now;
            $checkTime->modify("+{$i} minutes");

            if ($this->isDue($expression, $checkTime)) {
                if ($i === 0) {
                    return 'Now';
                }
                if ($i < 60) {
                    return "in {$i} min";
                }
                if ($i < 1440) {
                    $hours = floor($i / 60);
                    return "in {$hours}h";
                }
            }
        }

        return 'unknown';
    }
}
