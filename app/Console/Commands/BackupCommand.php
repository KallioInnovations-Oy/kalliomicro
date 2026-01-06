<?php

declare(strict_types=1);

namespace App\Console\Commands;

use KallioMicro\Console\Commands\TaskCommand;
use KallioMicro\Console\Input;

/**
 * BackupCommand - Example database backup task
 *
 * This is an example command showing how to create scheduled tasks.
 */
class BackupCommand extends TaskCommand
{
    protected string $name = 'task:backup';
    protected string $description = 'Create a database backup';
    protected string $schedule = '0 2 * * *'; // Daily at 2:00 AM

    protected array $options = [
        'type' => 'Backup type: daily, weekly, monthly (default: daily)',
        'output' => 'Custom output directory',
    ];

    public function handle(Input $input): int
    {
        $this->logStart();

        $type = $input->getOption('type', 'daily');
        $outputDir = $input->getOption('output', '/mnt/backup/sql');

        // Ensure output directory exists
        if (!is_dir($outputDir)) {
            $this->error("Output directory does not exist: {$outputDir}");
            $this->logFailed("Output directory does not exist");
            return 1;
        }

        $filename = sprintf(
            '%s/KM_%s_%s.sql',
            rtrim($outputDir, '/'),
            $type,
            date('Y-m-d')
        );

        $this->info("Creating {$type} backup...");
        $this->line("  Output: {$filename}");

        // Get database config
        $dbHost = env('DB_HOST', 'localhost');
        $dbName = env('DB_DATABASE', 'kalliomicro_production');
        $dbUser = env('DB_USERNAME', 'root');
        $dbPass = env('DB_PASSWORD', '');

        // Build mysqldump command
        $command = sprintf(
            'mysqldump --host=%s --user=%s --password=%s %s > %s 2>&1',
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName),
            escapeshellarg($filename)
        );

        // Execute backup
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->error("Backup failed with exit code: {$returnCode}");
            $this->logFailed("mysqldump failed");
            return 1;
        }

        // Clean old backups based on type
        $this->cleanOldBackups($type, $outputDir);

        $fileSize = file_exists($filename) ? $this->formatBytes(filesize($filename)) : 'unknown';
        $this->logComplete("Created {$filename} ({$fileSize})");

        return 0;
    }

    private function cleanOldBackups(string $type, string $dir): void
    {
        $retentionDays = match ($type) {
            'daily' => 7,
            'weekly' => 28,
            'monthly' => 120,
            default => 7,
        };

        $pattern = "{$dir}/KM_{$type}_*.sql";
        $threshold = time() - ($retentionDays * 86400);

        $this->comment("  Cleaning backups older than {$retentionDays} days...");

        $deleted = 0;
        foreach (glob($pattern) as $file) {
            if (filemtime($file) < $threshold) {
                unlink($file);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->comment("  Removed {$deleted} old backup(s)");
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
