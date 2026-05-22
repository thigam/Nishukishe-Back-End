<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Incident;

class CleanupStaleIncidents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-stale-incidents';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sweep database for stale commuter occurrences and delete them.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // 1. Delete *unverified* incidents older than 12 hours that do not have a future end_time
        $unverifiedCount = Incident::where('is_verified', false)
            ->where('reported_at', '<', now()->subHours(12))
            ->where(function ($q) {
                $q->whereNull('end_time')
                  ->orWhere('end_time', '<', now());
            })
            ->delete();

        // 2. Delete *verified* incidents older than 48 hours that do not have a future end_time
        $verifiedCount = Incident::where('is_verified', true)
            ->where('reported_at', '<', now()->subHours(48))
            ->where(function ($q) {
                $q->whereNull('end_time')
                  ->orWhere('end_time', '<', now());
            })
            ->delete();

        $this->info("Successfully cleaned up {$unverifiedCount} unverified and {$verifiedCount} verified incidents.");
    }
}
