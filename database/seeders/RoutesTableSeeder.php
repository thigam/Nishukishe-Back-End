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

        $uniqueInserts = [];
        foreach ($routesRaw as $r) {
            [$routeId, $saccoId] = explode('_', $r['id'], 2);

            $parts = explode(': ', $r['name'], 2);
            $desc  = $parts[1] ?? $r['name'];
            if (preg_match('/\{(\d+)\}\s*$/', $desc, $m)) {
                $routeNumber = $m[1];
            } else {
                $routeNumber = '';
            }

            $descNoNum = preg_replace('/\s*\{\d+\}\s*$/', '', $desc);
            [$start, $end] = array_map('trim', explode(' - ', $descNoNum, 2) + [null, null]);

            $uniqueInserts[$routeId] = [
                'route_id'         => $routeId,
                'route_number'     => $routeNumber,
                'route_start_stop' => $start ?? '',
                'route_end_stop'   => $end   ?? '',
            ];
        }

        DB::table('routes')->upsert(
            array_values($uniqueInserts),
            ['route_id'],
            ['route_number','route_start_stop','route_end_stop']
        );

        $this->command->info("Upserted " . count($uniqueInserts) . " routes into `routes` table.");
    }
}
