<?php

namespace App\Console\Commands;

use App\Models\SaccoRoutes;
use App\Models\Trip;
use App\Models\Variation;
use Illuminate\Console\Command;

class SeedRouteFlags extends Command
{
    protected $signature = 'routes:seed-flags';

    protected $description = 'Set scheduled and has_variations flags on sacco routes';

    public function handle(): int
    {
        $tripRouteIds = Trip::query()
            ->whereNotNull('sacco_route_id')
            ->distinct()
            ->pluck('sacco_route_id');

        if ($tripRouteIds->isNotEmpty()) {
            SaccoRoutes::whereIn('sacco_route_id', $tripRouteIds)->update(['scheduled' => true]);
        }

        $variationRouteIds = Variation::query()
            ->whereNotNull('sacco_route_id')
            ->distinct()
            ->pluck('sacco_route_id');

        if ($variationRouteIds->isNotEmpty()) {
            SaccoRoutes::whereIn('sacco_route_id', $variationRouteIds)->update(['has_variations' => true]);
        }

        $this->info('Route flags seeded');

        return Command::SUCCESS;
    }
}
