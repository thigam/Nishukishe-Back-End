<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\SaccoRoutes;

class SaccoRoutesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $jsonPath = database_path('data/routes.json');
        if (!file_exists($jsonPath)) {
            $this->command->error("routes.json not found at {$jsonPath}");
            return;
        }

        $raw       = file_get_contents($jsonPath);
        $routesRaw = json_decode($raw, true);

        $inserts = array_map(function ($r) {
            // r['id'] is like "70301000511_CH0003"
            [$routeId, $saccoId] = explode('_', $r['id'], 2);

            // r['name'] is like "Citi Hoppa: Town - Jamhuri {5}"
            //  → split off the descriptor after the colon
            $parts = explode(': ', $r['name'], 2);
            $desc  = $parts[1] ?? $r['name'];

            // Grab the number inside {…}, or default to the empty string
if (preg_match('/\{(\d+)\}\s*$/', $desc, $m)) {
    $routeNumber = $m[1];
} else {
    // fallback: use the raw route ID, or just an empty string
    $routeNumber = '';
}


            // strip the "{5}" suffix
            $descNoNum = preg_replace('/\s*\{\d+\}\s*$/', '', $desc);

            // split "Town - Jamhuri" into start/end
	    [$start, $end] = array_map('trim', explode(' - ', $descNoNum, 2) + [null, null]);

	                // pick up whichever key actually exists in your JSON:
            $stops = $r['stopIds']   ??  // camelCase
		    $r['stop_ids'] ??  // snake_case
		    $r['stops'] ??
                     [];

	    return [
    'sacco_route_id'   => SaccoRoutes::generateSaccoRouteId($saccoId, $routeId),		    
    'route_id'         => $routeId,
    'sacco_id'         => $saccoId,
    'peak_fare'        => 100,
    'off_peak_fare'    => 100,
    'coordinates'      => json_encode($r['coordinates']),
    'stop_ids'         => json_encode($stops),
    'county_id'        => $r['county'] ?? null,
    'mode'             => $r['mode'] ?? null,
    'waiting_time'     => $r['waitingTime'] ?? null,
];

        }, $routesRaw);

        // Idempotent upsert: insert new, update existing on (route_id, sacco_id)
        DB::table('sacco_routes')->upsert(
            $inserts,
                ['sacco_route_id'], // now upsert by the actual PK
    [
        'route_id',
        'sacco_id',
        'peak_fare',
        'off_peak_fare',
        'stop_ids',
        'coordinates',
        'county_id',
        'mode',
        'waiting_time',
    ]
        );

        $this->command->info("Upserted " . count($inserts) . "sacco_routes.");
    }
}

