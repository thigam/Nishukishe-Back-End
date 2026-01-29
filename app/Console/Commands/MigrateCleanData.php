<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PostCleanSaccoRoute;
use App\Models\PostCleanStop;
use App\Models\SaccoRoutes;
use App\Models\Stops;
use Illuminate\Support\Facades\DB;

class MigrateCleanData extends Command
{
    protected $signature = 'app:migrate-clean-data';

    protected $description = 'Move post-clean records into the main tables';

    public function handle()
    {
        DB::transaction(function () {
            foreach (PostCleanStop::cursor() as $pcStop) {
                Stops::updateOrCreate([
                    'stop_id' => $pcStop->stop_id,
                ], [
                    'stop_name'   => $pcStop->stop_name,
                    'stop_lat'    => $pcStop->stop_lat,
                    'stop_long'   => $pcStop->stop_long,
                    'county_id'   => $pcStop->county_id,
                    'direction_id'=> $pcStop->direction_id,
                ]);
                $pcStop->delete();
            }

            foreach (PostCleanSaccoRoute::cursor() as $pcRoute) {
                SaccoRoutes::updateOrCreate([
                    'route_id' => $pcRoute->route_id,
                    'sacco_id' => $pcRoute->sacco_id,
                ], [
                    'route_number'     => $pcRoute->route_number,
                    'route_start_stop' => $pcRoute->route_start_stop,
                    'route_end_stop'   => $pcRoute->route_end_stop,
                    'peak_fare'        => $pcRoute->peak_fare,
                    'off_peak_fare'    => $pcRoute->off_peak_fare,
                    'currency'         => $pcRoute->currency ?? 'KES',
                    'coordinates'      => $pcRoute->coordinates,
                    'stop_ids'         => $pcRoute->stop_ids,
                    'county_id'        => $pcRoute->county_id,
                    'mode'             => $pcRoute->mode,
                    'waiting_time'     => $pcRoute->waiting_time,
                ]);
                $pcRoute->delete();
            }
        });

        $this->info('Clean data migrated successfully.');
    }
}
