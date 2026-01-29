<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SaccoRoutes;
use App\Models\Directions;
use App\Models\Stops;

class PopulateDirections extends Command
{
    protected $signature = 'directions:populate';
    protected $description = 'Build direction records from sacco_routes → stops';

    public function handle()
    {
        $this->info("Scanning all routes…");
        $count = 0;

        foreach (SaccoRoutes::all() as $route) {
            // stop_ids is already cast to array by the model
            $ids = $route->stop_ids ?: [];

            if (empty($ids)) {
                $this->warn("Skipping route {$route->route_id}: no stop_ids");
                continue;
            }

            $count++;
            $last = end($ids);

            foreach ($ids as $stopId) {
                if (! $stop = Stops::find($stopId)) {
                    continue;
                }

                $direction = Directions::firstOrNew(['direction_id' => $stopId]);
                $direction->direction_heading   = $stop->stop_name;
                $direction->direction_latitude  = $stop->stop_lat;
                $direction->direction_longitude = $stop->stop_long;

                // merge this route into the JSON-array
                $dr = $direction->direction_routes ?: [];
                if (! in_array($route->sacco_route_id, $dr, true)) {
                    $dr[] = $route->sacco_route_id;
                }
                $direction->direction_routes = $dr;

                // if it’s the last stop, mark it in the ending array
                $de = $direction->direction_ending ?: [];
                if ($stopId === $last && ! in_array($route->route_id, $de, true)) {
                    $de[] = $route->route_id;
                }
                $direction->direction_ending = $de;

                $direction->save();
            }
        }

        $this->info("Processed {$count} route(s).");
        $this->info("Done populating directions.");
    }
}

