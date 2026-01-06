<?php

declare(strict_types=1);

namespace App\Console\Commands;

use KallioMicro\Console\Commands\TaskCommand;
use KallioMicro\Console\Input;

/**
 * CleanupCommand - Example cleanup task
 *
 * Demonstrates common cleanup patterns like:
 * - Clearing temporary files
 * - Removing old log entries
 * - Pruning expired sessions
 */
class CleanupCommand extends TaskCommand
{
    protected string $name = 'task:cleanup';
    protected string $description = 'Clean up temporary files and old data';
    protected string $schedule = '0 * * * *'; // Every hour

    protected array $options = [
        'dry-run' => 'Show what would be deleted without actually deleting',
        'all' => 'Run all cleanup tasks',
        'files' => 'Clean temporary files only',
        'logs' => 'Clean old logs only',
        'sessions' => 'Clean expired sessions only',
    ];

    public function handle(Input $input): int
    {
        $this->logStart();

        $dryRun = $input->hasOption('dry-run');
        $runAll = $input->hasOption('all') || (
            !$input->hasOption('files') &&
            !$input->hasOption('logs') &&
            !$input->hasOption('sessions')
        );

        if ($dryRun) {
            $this->warning("DRY RUN - No files will be deleted");
            $this->line('');
        }

        $totalCleaned = 0;

        // Clean temporary files
        if ($runAll || $input->hasOption('files')) {
            $totalCleaned += $this->cleanTempFiles($dryRun);
        }

        // Clean old logs
        if ($runAll || $input->hasOption('logs')) {
            $totalCleaned += $this->cleanOldLogs($dryRun);
        }

        // Clean expired sessions
        if ($runAll || $input->hasOption('sessions')) {
            $totalCleaned += $this->cleanExpiredSessions($dryRun);
        }

        $this->line('');
        $this->logComplete("Cleaned {$totalCleaned} items");

        return 0;
    }

    private function cleanTempFiles(bool $dryRun): int
    {
        $this->info('Cleaning temporary files...');

        $tempDir = defined('KALLIOMICRO_BASE_PATH')
            ? KALLIOMICRO_BASE_PATH . '/storage/temp'
            : sys_get_temp_dir();

        if (!is_dir($tempDir)) {
            $this->comment("  Temp directory not found: {$tempDir}");
            return 0;
        }

        $count = 0;
        $threshold = time() - 86400; // 24 hours old

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getMTime() < $threshold) {
                $this->comment("  " . ($dryRun ? "[DRY] " : "") . "Removing: " . $file->getPathname());
                if (!$dryRun) {
                    @unlink($file->getPathname());
                }
                $count++;
            }
        }

        $this->success("  Cleaned {$count} temporary file(s)");
        return $count;
    }

    private function cleanOldLogs(bool $dryRun): int
    {
        $this->info('Cleaning old log files...');

        $logDir = defined('KALLIOMICRO_BASE_PATH')
            ? KALLIOMICRO_BASE_PATH . '/storage/logs'
            : '/var/log';

        if (!is_dir($logDir)) {
            $this->comment("  Log directory not found: {$logDir}");
            return 0;
        }

        $count = 0;
        $threshold = time() - (30 * 86400); // 30 days old

        foreach (glob("{$logDir}/*.log") as $file) {
            if (filemtime($file) < $threshold) {
                $this->comment("  " . ($dryRun ? "[DRY] " : "") . "Removing: " . basename($file));
                if (!$dryRun) {
                    @unlink($file);
                }
                $count++;
            }
        }

        $this->success("  Cleaned {$count} log file(s)");
        return $count;
    }

    private function cleanExpiredSessions(bool $dryRun): int
    {
        $this->info('Cleaning expired sessions...');

        // This would typically interact with your session storage
        // For file-based sessions:
        $sessionDir = session_save_path() ?: sys_get_temp_dir();

        if (!is_dir($sessionDir)) {
            $this->comment("  Session directory not found");
            return 0;
        }

        $count = 0;
        $maxLifetime = (int) ini_get('session.gc_maxlifetime') ?: 1440;
        $threshold = time() - $maxLifetime;

        foreach (glob("{$sessionDir}/sess_*") as $file) {
            if (filemtime($file) < $threshold) {
                $this->comment("  " . ($dryRun ? "[DRY] " : "") . "Removing session: " . basename($file));
                if (!$dryRun) {
                    @unlink($file);
                }
                $count++;
            }
        }

        $this->success("  Cleaned {$count} expired session(s)");
        return $count;
    }
}
