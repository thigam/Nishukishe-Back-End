<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore-latest';
    protected $description = 'Restore ONLY the database from the latest Spatie backup ZIP file';

    public function handle()
    {
        $disk = config('backup.backup.destination.disks')[0] ?? 'local';

        // 1. Find all ZIP files in the backup disk
        $files = Storage::disk($disk)->allFiles('/Laravel');
        $zipFiles = array_filter($files, fn($f) => str_ends_with($f, '.zip'));

        if (empty($zipFiles)) {
            $this->error("No backup ZIP files found on disk [$disk].");
            return 1;
        }

        // 2. Get the most recent backup
        $latestBackup = collect($zipFiles)
            ->sortByDesc(fn($f) => Storage::disk($disk)->lastModified($f))
            ->first();

        $this->info("Latest backup found: {$latestBackup}");

        // 3. Confirm overwrite
        $this->warn("WARNING: This will overwrite your current database!");
        if (!$this->confirm('Do you want to continue?')) {
            $this->info('Restore cancelled.');
            return 0;
        }

        // 4. Extract backup ZIP
        $zipPath = Storage::disk($disk)->path($latestBackup);
        $tempDir = storage_path('app/backup-temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) === TRUE) {
            $zip->extractTo($tempDir);
            $zip->close();
            $this->info("Backup extracted to: $tempDir");
        } else {
            $this->error("Failed to open ZIP file.");
            return 1;
        }

        // 5. Find SQL dump
        $dbDumps = glob($tempDir . '/db-dumps/*.sql');
        if (empty($dbDumps)) {
            $this->error("No SQL dump found in the backup.");
            return 1;
        }

        $sqlFile = $dbDumps[0];
        $this->info("Restoring database from: {$sqlFile}");

        // 6. Database credentials
        $dbName = env('DB_DATABASE');
        $dbUser = env('DB_USERNAME');
        $dbPass = env('DB_PASSWORD');
        $dbHost = env('DB_HOST', '127.0.0.1');

        // 7. Run MySQL restore
        $command = "mysql -h {$dbHost} -u {$dbUser} -p'{$dbPass}' {$dbName} < {$sqlFile}";
        exec($command, $output, $resultCode);

        if ($resultCode === 0) {
            $this->info("✅ Database restored successfully.");
        } else {
            $this->error("❌ Database restore failed.");
        }

        // 8. Clean up
        exec("rm -rf {$tempDir}");
    }
}
