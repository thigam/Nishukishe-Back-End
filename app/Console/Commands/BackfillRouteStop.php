<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SaccoRoutes;
use Illuminate\Support\Facades\DB;

class BackfillRouteStop extends Command
{
    protected $signature = 'routes:backfill-route-stop';
    protected $description = 'Populate route_stop pivot table from routes.stop_ids';

    public function handle()
    {
        DB::table('route_stop')->truncate();

        foreach (SaccoRoutes::cursor() as $route) {
            $order = 1;
            foreach ($route->stop_ids ?: [] as $stopId) {
                DB::table('route_stop')->insertOrIgnore([
                    'route_id' => $route->route_id,
                    'stop_id'  => $stopId,
                    'sequence' => $order++,
                ]);
            }
        }

        $this->info('route_stop table populated');
    }
}
