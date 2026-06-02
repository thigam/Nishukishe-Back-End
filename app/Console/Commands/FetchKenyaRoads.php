<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchKenyaRoads extends Command
{
    protected $signature = 'app:fetch-kenya-roads';
    protected $description = 'Fetch major Kenyan roads from OpenStreetMap Overpass API and store them in the local database.';

    public function handle()
    {
        $this->info("Fetching major Kenyan roads from Overpass API...");

        // Kenya Bounding Box: Min Lat: -4.72, Min Lng: 33.91, Max Lat: 4.63, Max Lng: 41.91
        // We fetch ways with highway = motorway, trunk, primary, secondary having a name tag
        $query = '[out:json][timeout:300];way["highway"~"motorway|trunk|primary|secondary"]["name"](-4.72,33.91,4.63,41.91);out geom;';

        $urls = [
            'https://overpass-api.de/api/interpreter',
            'https://overpass.kumi.systems/api/interpreter',
            'https://lz4.overpass-api.de/api/interpreter',
            'https://z.overpass-api.de/api/interpreter',
        ];

        $elements = [];

        foreach ($urls as $url) {
            $this->info("Sending query to Overpass API: {$url}");

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'NishukisheRoadImport/1.0 (contact@nishukishe.com)',
                    'Accept' => 'application/json',
                ])->timeout(180)
                  ->asForm()
                  ->post($url, ['data' => $query]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (isset($data['elements'])) {
                        $elements = $data['elements'];
                        break;
                    }
                } else {
                    $this->warn("Overpass API endpoint {$url} failed with status " . $response->status());
                }
            } catch (\Exception $e) {
                $this->warn("Overpass API endpoint {$url} connection failed: " . $e->getMessage());
            }
        }

        try {
            if (empty($elements)) {
                $this->error("All Overpass API endpoints failed or returned empty. Please try again later.");
                return self::FAILURE;
            }

            $total = count($elements);
            $this->info("Successfully fetched {$total} road elements. Processing...");

            if ($total === 0) {
                $this->warn("No road elements returned. Verify bounding box or query.");
                return self::SUCCESS;
            }

            $inserted = 0;
            $updated = 0;

            DB::beginTransaction();

            foreach ($elements as $element) {
                if (($element['type'] ?? '') !== 'way') {
                    continue;
                }

                $id = $element['id'];
                $tags = $element['tags'] ?? [];
                $name = $tags['name'] ?? null;
                $type = $tags['highway'] ?? null;
                $geometry = $element['geometry'] ?? [];

                if (!$name || empty($geometry)) {
                    continue;
                }

                $lats = array_column($geometry, 'lat');
                $lons = array_column($geometry, 'lon');

                $latMin = min($lats);
                $latMax = max($lats);
                $lngMin = min($lons);
                $lngMax = max($lons);

                // Map 'lon' to 'lng' in geometry array for local consistency
                $formattedGeometry = array_map(function ($point) {
                    return [
                        'lat' => $point['lat'],
                        'lng' => $point['lon'],
                    ];
                }, $geometry);

                $exists = DB::table('roads')->where('id', $id)->exists();

                DB::table('roads')->updateOrInsert(
                    ['id' => $id],
                    [
                        'name' => $name,
                        'type' => $type,
                        'geometry' => json_encode($formattedGeometry),
                        'lat_min' => $latMin,
                        'lat_max' => $latMax,
                        'lng_min' => $lngMin,
                        'lng_max' => $lngMax,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                if ($exists) {
                    $updated++;
                } else {
                    $inserted++;
                }
            }

            DB::commit();

            $this->info("Road synchronization complete! Inserted: {$inserted}, Updated: {$updated}");
            Log::info("Road synchronization complete. Inserted: {$inserted}, Updated: {$updated}");

            return self::SUCCESS;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("An error occurred during import: " . $e->getMessage());
            Log::error("Road import failed", ['error' => $e->getMessage()]);
            return self::FAILURE;
        }
    }
}
