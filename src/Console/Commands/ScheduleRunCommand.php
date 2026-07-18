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
 *
 * Due tasks run inline, sequentially, each under a per-task non-blocking
 * flock (storage/framework/schedule-*.lock): a task still running from a
 * previous tick is skipped, not doubled, and the kernel drops the lock if
 * the process dies. The lock is host-local — running schedule:run on
 * multiple hosts still requires a distributed lock inside handle() (see
 * Console::schedule()). Lock files are never unlinked: removing a file
 * while another process holds its lock lets two holders "lock" different
 * inodes of the same path.
 */
class ScheduleRunCommand extends Command
{
    protected string $name = 'schedule:run';
    protected string $description = 'Run scheduled tasks that are due';
    private ?string $lockDirectory = null;
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
        $tasksSkipped = 0;
        $forceRun = $input->hasOption('force');

        foreach ($tasks as $task) {
            $name = $task['command'];

            if (!$forceRun && !$this->isDue($task['schedule'], $currentTime)) {
                continue;
            }

            // --force bypasses the due-check, never the lock. A lock-infra
            // failure (permissions on one lock file) fails THIS task loudly
            // but must not abort the remaining due tasks.
            try {
                $lock = $this->acquireLock($name);
            } catch (\RuntimeException $e) {
                $this->error("  {$name}: {$e->getMessage()}");
                $tasksFailed++;
                continue;
            }

            if ($lock === null) {
                $this->comment("Skipping {$name}: previous run still in progress.");
                $tasksSkipped++;
                continue;
            }

            try {
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
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        if ($tasksRun === 0 && $tasksFailed === 0 && $tasksSkipped === 0) {
            $this->comment('No scheduled tasks are due.');
        } else {
            $this->line('');
            $this->info("Tasks run: {$tasksRun}, Failed: {$tasksFailed}, Skipped: {$tasksSkipped}");
        }

        return $tasksFailed > 0 ? 1 : 0;
    }

    /**
     * Acquire the per-task overlap lock, or report the task as still running.
     *
     * @return resource|null Lock handle to release after execution, or null
     *                       when a previous run of the task still holds it.
     */
    private function acquireLock(string $taskName)
    {
        $dir = $this->lockDirectory ??= $this->ensureLockDirectory();

        // 'task:backup' → 'task-backup'; the md5 suffix keeps sanitized
        // collisions ('a:b' vs 'a-b') on distinct lock files.
        $safe = preg_replace('/[^A-Za-z0-9_\-]+/', '-', $taskName);
        $path = $dir . '/schedule-' . $safe . '-' . substr(md5($taskName), 0, 8) . '.lock';

        $handle = fopen($path, 'c'); // create-or-open, never truncates
        if ($handle === false) {
            throw new \RuntimeException(
                "Unable to open scheduler lock file {$path}; check storage/framework permissions."
            );
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    private function ensureLockDirectory(): string
    {
        $dir = $this->app()->storagePath('framework');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(
                "Unable to create scheduler lock directory {$dir}; check storage/ permissions."
            );
        }

        return $dir;
    }

    /**
     * List all scheduled tasks
     *
     * @param array<int, array{command: string, schedule: string}> $tasks
     */
    private function listScheduledTasks(array $tasks): int
    {
        $this->line('');
        $this->info('Scheduled Tasks:');
        $this->line('');

        $rows = [];
        $currentTime = new \DateTime();

        foreach ($tasks as $task) {
            $isDue = $this->isDue($task['schedule'], $currentTime) ? 'Yes' : 'No';
            $nextRun = $this->getNextRunTime($task['schedule']);

            $rows[] = [$task['command'], $task['schedule'], $isDue, $nextRun];
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
        // The list separator splits FIRST. Testing '/' or '-' against the
        // whole field made a mixed field never reach the list branch:
        // explode('-', '1,3-5', 2) yields start '1,3' → 1, so '0 1,3-5 * * *'
        // was due at 02:00 and '0 1-5,10 * * *' was not due at 10:00 — a task
        // running at a time it was never scheduled for.
        $elements = explode(',', $field);

        // Validate every element before matching any: docs/console.md promises
        // a field outside the grammar silently isn't due rather than crashing,
        // and one bad element must not be masked by a good sibling matching
        // ('5,*/0' stays never-due, as documented). The step is required
        // non-zero here, which is also what keeps matchesElement()'s modulo
        // away from a DivisionByZeroError that would kill the whole tick.
        $step = '(?:/0*[1-9]\d*)';

        foreach ($elements as $element) {
            if (preg_match("#^(?:\*{$step}?|\d+(?:-\d+{$step}?)?)$#", $element) !== 1) {
                return false;
            }
        }

        foreach ($elements as $element) {
            if ($this->matchesElement($element, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match one comma-free cron element: '*', '5', 'a-b', '*' + '/n', 'a-b/n'.
     *
     * Only ever called on elements matchesField() has already validated,
     * so the step is known to be a positive integer.
     */
    private function matchesElement(string $element, int $value): bool
    {
        if ($element === '*') {
            return true;
        }

        if (ctype_digit($element)) {
            return (int) $element === $value;
        }

        $step = 1;

        // Step is stripped before the range is read, or '0-30/5' is misread
        // as the range '0-30' with the step silently ignored.
        if (str_contains($element, '/')) {
            [$element, $stepPart] = explode('/', $element, 2);
            $step = (int) $stepPart;
        }

        if ($element === '*') {
            return $value % $step === 0;
        }

        [$start, $end] = explode('-', $element, 2);

        if ($value < (int) $start || $value > (int) $end) {
            return false;
        }

        return ($value - (int) $start) % $step === 0;
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
