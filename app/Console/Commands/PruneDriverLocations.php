<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DriverLocation;
use Illuminate\Support\Facades\Schema;

class PruneDriverLocations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'locations:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune driver locations older than 10 days to save storage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting pruning of driver locations...');

        $count = DriverLocation::where('recorded_at', '<', now()->subDays(10))->delete();

        $this->info("Successfully deleted {$count} records older than 10 days.");
    }
}
