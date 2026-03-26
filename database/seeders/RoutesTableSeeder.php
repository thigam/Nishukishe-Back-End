<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RoutesTableSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('data/routes.json');
        if (! File::exists($jsonPath)) {
            $this->command->error("data/routes.json not found at {$jsonPath}");
            return;
        }

        $raw       = File::get($jsonPath);
        $routesRaw = json_decode($raw, true);

        $inserts = array_map(function ($r) {
            // split the composite id "60101007C11_MT0014"
            [$routeId, $saccoId] = explode('_', $r['id'], 2);

            // pull the "{5}" out of the description
            $parts = explode(': ', $r['name'], 2);
            $desc  = $parts[1] ?? $r['name'];
            if (preg_match('/\{(\d+)\}\s*$/', $desc, $m)) {
                $routeNumber = $m[1];
            } else {
                $routeNumber = '';
            }

            // strip the "{5}" and split "Start – End"
            $descNoNum = preg_replace('/\s*\{\d+\}\s*$/', '', $desc);
            [$start, $end] = array_map('trim', explode(' - ', $descNoNum, 2) + [null, null]);

            return [
                'route_id'         => $routeId,
                'route_number'     => $routeNumber,
                'route_start_stop' => $start ?? '',
                'route_end_stop'   => $end   ?? '',
            ];
        }, $routesRaw);

        DB::table('routes')->upsert(
            $inserts,
            ['route_id'],                          // unique key
            ['route_number','route_start_stop','route_end_stop']  // cols to update
        );

        $this->command->info("Upserted " . count($inserts) . " routes into `routes` table.");
    }
}

