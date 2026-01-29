<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\StationRaptor;
use App\Models\Stops;
use App\Models\Directions;
use App\Services\H3Wrapper;
use Illuminate\Support\Facades\DB;

class DebugSearch extends Command
{
    protected $signature = 'debug:search';
    protected $description = 'Debug multileg search from Westlands to Kitengela';

    private $stationRaptor;

    public function __construct(StationRaptor $stationRaptor)
    {
        parent::__construct();
        $this->stationRaptor = $stationRaptor;
    }

    public function handle()
    {
        $this->info("Starting Debug Search: Westlands -> Kitengela");

        $scenario = $this->ask('Select scenario: [1] Nairobi->Kajiado, [2] Mombasa->Mtwapa, [3] Custom', '1');

        if ($scenario == '1') {
            // Nairobi -> Kajiado
            $olat = -1.2833;
            $olng = 36.8167; // Nairobi CBD
            $dlat = -1.8523;
            $dlng = 36.7768; // Kajiado
        } elseif ($scenario == '2') {
            // Mombasa -> Mtwapa
            $olat = -4.0435;
            $olng = 39.6682; // Mombasa
            $dlat = -3.9423;
            $dlng = 39.7456; // Mtwapa
        } else {
            $olat = (float) $this->ask('Origin Lat', -1.268);
            $olng = (float) $this->ask('Origin Lng', 36.808);
            $dlat = (float) $this->ask('Dest Lat', -1.515);
            $dlng = (float) $this->ask('Dest Lng', 36.961);
        }

        $this->info("Origin: $olat, $olng");
        $this->info("Dest: $dlat, $dlng");

        // 1. Find Origin/Dest Stops
        $originStops = $this->seedStopsWithHubs($olat, $olng);
        $destStops = $this->seedStopsWithHubs($dlat, $dlng);

        $this->info("Origin Stops Found: " . $originStops->count());
        foreach ($originStops as $s) {
            $this->line(" - " . $s['stop_name'] . " (" . $s['stop_id'] . ")");
        }

        $this->info("Dest Stops Found: " . $destStops->count());
        foreach ($destStops as $s) {
            $this->line(" - " . $s['stop_name'] . " (" . $s['stop_id'] . ")");
        }

        if ($originStops->isEmpty() || $destStops->isEmpty()) {
            $this->error("Stops not found!");
            return;
        }

        // 2. Load Raptor Data
        $this->info("Loading StationRaptor Data...");
        $this->stationRaptor->loadData();
        $this->info("Data Loaded.");

        // 3. Run Search
        $oStop = $originStops->first();
        $dStop = $destStops->first();

        $this->info("Searching from " . $oStop['stop_id'] . " to " . $dStop['stop_id']);

        $results = $this->stationRaptor->search($oStop['stop_id'], $dStop['stop_id']);

        if (isset($results['error'])) {
            $this->error("Search Error: " . $results['error']);
            return;
        }

        $this->info("Raw Paths Found: " . count($results));

        // 4. Expand Paths
        foreach ($results as $i => $path) {
            $this->info("Expanding Path #$i...");
            $detailed = $this->stationRaptor->expandPath($path, $oStop['stop_id'], $dStop['stop_id']);

            if (empty($detailed)) {
                $this->warn(" - Failed to expand path.");
            } else {
                $this->info(" - Expansion Successful. Legs: " . count($detailed));
                foreach ($detailed as $leg) {
                    $this->line("   > " . $leg['from_station'] . " -> " . $leg['to_station'] . " via " . $leg['route_id'] . " (Walk Valid: " . ($leg['walk_valid'] ? 'Yes' : 'NO') . ")");
                }
            }
        }
    }

    // Copied Helpers
    private function seedStopsWithHubs($lat, $lng, $baseCount = 3, $maxK = 6, $hubCap = 2, $totalCap = 5)
    {
        $near = $this->nearestStops($lat, $lng, $baseCount, $maxK);
        $hubs = $this->lookupTopHubsForPoint($lat, $lng, $hubCap);

        return collect($near)->merge($hubs)
            ->unique('stop_id')
            ->take($totalCap)
            ->values();
    }

    private function nearestStops($lat, $lng, $count = 3, $maxK = 6)
    {
        $index = H3Wrapper::latLngToCell($lat, $lng, 9);

        $expr = '(6371000 * acos(cos(radians(?)) * cos(radians(direction_latitude)) * ' .
            'cos(radians(direction_longitude) - radians(?)) + sin(radians(?)) * ' .
            'sin(radians(direction_latitude))))';

        $picked = collect();

        for ($k = 0; $k <= $maxK && $picked->count() < $count; $k++) {
            $cells = array_map('strval', H3Wrapper::kRing($index, $k));
            $rows = Directions::with('stop')
                ->whereIn('h3_index', $cells)
                ->selectRaw("*, {$expr} AS distance", [$lat, $lng, $lat])
                ->orderBy('distance')
                ->limit($count * 3)
                ->get()
                ->filter(fn($d) => $d->stop !== null);

            $picked = $picked->merge($rows)
                ->unique(fn($d) => $d->stop->stop_id)
                ->sortBy('distance')
                ->take($count);
        }

        return $picked->map(fn($d) => [
            'stop_id' => $d->stop->stop_id,
            'stop_name' => $d->stop->stop_name,
            'stop_lat' => $d->stop->stop_lat,
            'stop_long' => $d->stop->stop_long,
        ])->values();
    }

    private function lookupTopHubsForPoint(float $lat, float $lng, int $limit = 2): array
    {
        $regions = DB::table('transit_hub_regions')->get();
        $hit = [];
        foreach ($regions as $r) {
            $ok = false;
            if ($r->h3_cells) {
                $cells = json_decode($r->h3_cells, true) ?: [];
                $res = (int) ($r->h3_res ?? 7);
                $cell = H3Wrapper::latLngToCell($lat, $lng, $res);
                if (in_array((string) $cell, array_map('strval', $cells), true))
                    $ok = true;
            }
            if (!$ok && $r->polygon) {
                $poly = json_decode($r->polygon, true) ?: [];
                if ($poly)
                    $ok = $this->pointInPoly($lat, $lng, $poly);
            }
            if ($ok)
                $hit[] = (string) $r->region_id;
        }
        if (!$hit)
            return [];

        $rows = DB::table('transit_hubs')
            ->whereIn('region_id', $hit)
            ->orderBy('rank')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty())
            return [];

        $stops = Stops::whereIn('stop_id', $rows->pluck('stop_id'))->get()->keyBy('stop_id');
        $out = [];
        foreach ($rows as $row) {
            $s = $stops->get($row->stop_id);
            if (!$s)
                continue;
            $out[] = [
                'stop_id' => (string) $s->stop_id,
                'stop_name' => (string) $s->stop_name,
                'stop_lat' => (float) $s->stop_lat,
                'stop_long' => (float) $s->stop_long,
            ];
        }
        return $out;
    }

    private function pointInPoly($x, $y, $poly)
    {
        if (count($poly) < 3)
            return false;
        $inside = false;
        $p1x = $poly[0][0];
        $p1y = $poly[0][1];
        $n = count($poly);
        for ($i = 1; $i <= $n; $i++) {
            $p2x = $poly[$i % $n][0];
            $p2y = $poly[$i % $n][1];
            if ($y > min($p1y, $p2y)) {
                if ($y <= max($p1y, $p2y)) {
                    if ($x <= max($p1x, $p2x)) {
                        if ($p1y != $p2y) {
                            $xinters = ($y - $p1y) * ($p2x - $p1x) / ($p2y - $p1y) + $p1x;
                        }
                        if ($p1x == $p2x || $x <= $xinters) {
                            $inside = !$inside;
                        }
                    }
                }
            }
            $p1x = $p2x;
            $p1y = $p2y;
        }
        return $inside;
    }
}
