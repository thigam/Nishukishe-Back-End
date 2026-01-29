<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RefreshAndSeed extends Command
{
    protected $signature = 'app:refresh-all';
    protected $description = 'Run migrations fresh, seed DB, populate and backfill directions/routes/transfers';

    public function handle()
    {
        $this->warn('⚠ This will DROP and recreate all tables. Continue?');
        if (!$this->confirm('Do you want to proceed?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        $this->callArtisan('migrate:fresh');
        $this->callArtisan('db:seed', ['--force' => true]);
        $this->callArtisan('directions:populate');
        $this->callArtisan('directions:backfill-h3');
        $this->callArtisan('routes:backfill-route-stop');
        $this->callArtisan('routes:seed-flags');
        $this->callArtisan('directions:backfill-nearest-node');
        $this->callArtisan('transfers:build', [
            '--host' => 'http://192.168.8.30:5000',
            '--cap'  => 600
        ]);
        $this->callArtisan('transfers:approx', [
            '--speed' => 1.4,
            '--cap'   => 600
        ]);

        $this->info('✅ All tasks completed successfully.');
        return 0;
    }

    protected function callArtisan(string $command, array $params = [])
    {
        $this->info("▶ Running {$command}...");
        Artisan::call($command, $params, $this->getOutput());
    }
}
