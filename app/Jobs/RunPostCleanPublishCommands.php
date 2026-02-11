<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class RunPostCleanPublishCommands implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // Allow 10 minutes

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('RunPostCleanPublishCommands job started');

        try {
            Artisan::call('directions:populate');
            Log::info('directions:populate finished');

            Artisan::call('directions:backfill-h3');
            Log::info('directions:backfill-h3 finished');

            Artisan::call('routes:backfill-route-stop');
            Log::info('routes:backfill-route-stop finished');

            Artisan::call('routes:seed-flag');
            Log::info('routes:seed-flag finished');

            // Artisan::call('transfers:build', ['--host' => config('services.osrm.host'), '--cap' => 600]);

            Artisan::call('corridor:build');
            Log::info('corridor:build finished');

            Log::info('RunPostCleanPublishCommands job completed successfully');
        } catch (\Throwable $e) {
            Log::error('RunPostCleanPublishCommands job failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
