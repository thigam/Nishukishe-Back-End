<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Bookable;
use App\Models\Safari;
use Illuminate\Support\Facades\DB;

class FlushSafarisCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'safaris:flush {--force : Force deletion without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all safaris and their associated bookables from the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!$this->option('force') && !$this->confirm('Are you sure you want to delete ALL safaris? This cannot be undone.')) {
            $this->info('Operation cancelled.');
            return;
        }

        $this->info('Deleting safaris...');

        DB::transaction(function () {
            // Get all safaris
            $safariIds = Safari::pluck('id');
            $bookableIds = Safari::pluck('bookable_id');

            // Delete safaris
            Safari::whereIn('id', $safariIds)->delete();

            // Delete associated bookables
            // Note: If you have other types of bookables, this might be dangerous if not filtered.
            // Assuming Safaris are the main bookables or we only want to delete those linked to safaris.
            Bookable::whereIn('id', $bookableIds)->delete();
        });

        $this->info('All safaris flushed successfully.');
    }
}
