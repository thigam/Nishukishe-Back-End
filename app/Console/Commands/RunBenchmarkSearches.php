<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\StationRaptor;
use App\Models\Stops;
use App\Models\Directions;
use App\Services\H3Wrapper;
use Illuminate\Support\Facades\DB;

class RunBenchmarkSearches extends Command
{
    protected $signature = 'search:benchmark {--debug : Show detailed path legs for each search}';
    protected $description = 'Run 25 benchmark searches to verify pathfinding logic';

    private $stationRaptor;

    public function __construct(StationRaptor $stationRaptor)
    {
        parent::__construct();
        $this->stationRaptor = $stationRaptor;
    }

    public function handle()
    {
        $benchmarks = $this->getBenchmarks();
        $results = [];

        $this->info("Starting Benchmark Suite (25 searches)...");
        $this->output->progressStart(count($benchmarks));

        // 1. Preload Raptor Data
        $this->stationRaptor->loadData();

        foreach ($benchmarks as $index => $benchmark) {
            $report = [
                'id' => $index + 1,
                'description' => $benchmark['description'],
                'type' => $benchmark['type'],
                'success' => false,
                'error' => null,
                'paths_found' => 0,
                'min_legs' => null,
                'min_duration' => null,
            ];

            try {
                // 2. Resolve Stops from Coordinates
                $originStops = $this->seedStopsWithHubs($benchmark['olat'], $benchmark['olng']);
                $destStops = $this->seedStopsWithHubs($benchmark['dlat'], $benchmark['dlng']);

                if ($originStops->isEmpty() || $destStops->isEmpty()) {
                    $report['error'] = "Origin or Dest stops not found near coordinates.";
                } else {
                    $oStop = $originStops->first();
                    $dStop = $destStops->first();

                    // 3. Run Search
                    $searchResult = $this->stationRaptor->search($oStop['stop_id'], $dStop['stop_id']);

                    if (isset($searchResult['error'])) {
                        $report['error'] = $searchResult['error'];
                    } elseif (!empty($searchResult)) {
                        $report['success'] = true;
                        $report['paths_found'] = count($searchResult);

                        // 4. Expand first path to get duration/legs for the report
                        $bestPath = $searchResult[0];
                        $detailed = $this->stationRaptor->expandPath($bestPath, $oStop['stop_id'], $dStop['stop_id']);

                        if (!empty($detailed)) {
                            $report['min_legs'] = count($detailed);
                            $report['min_duration'] = $this->estimateDuration($detailed);
                        }

                        if ($this->option('debug')) {
                            $this->line("\n[Benchmark #{$report['id']}] {$benchmark['description']}");
                            foreach ($detailed as $leg) {
                                $this->line("  > {$leg['from_station']} -> {$leg['to_station']} (Route: {$leg['route_id']})");
                            }
                        }
                    } else {
                        $report['error'] = "No paths found.";
                    }
                }
            } catch (\Exception $e) {
                $report['error'] = $e->getMessage();
            }

            $results[] = $report;
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        // 5. Output Summary Table
        $this->info("\n--- Benchmark Summary ---");
        $headers = ['#', 'Type', 'Description', 'Status', 'Paths', 'Legs', 'Duration', 'Error'];
        $rows = array_map(function ($r) {
            return [
                $r['id'],
                $r['type'],
                $r['description'],
                $r['success'] ? '<info>PASS</info>' : '<error>FAIL</error>',
                $r['paths_found'],
                $r['min_legs'] ?? '-',
                $r['min_duration'] ? round($r['min_duration'], 1) . 'm' : '-',
                $r['error'] ? substr($r['error'], 0, 30) . (strlen($r['error']) > 30 ? '...' : '') : '-',
            ];
        }, $results);

        $this->table($headers, $rows);

        $passed = count(array_filter($results, fn($r) => $r['success']));
        $this->info("\nScore: $passed / " . count($benchmarks) . " passed.");
    }

    private function estimateDuration(array $detailed): float
    {
        $total = 0;
        foreach ($detailed as $leg) {
            $trip = DB::table('trips')->where('route_id', $leg['route_id'])->first();
            if ($trip) {
                $st1 = DB::table('stop_times')->where('trip_id', $trip->trip_id)->where('stop_id', $leg['from_stop'])->first();
                $st2 = DB::table('stop_times')->where('trip_id', $trip->trip_id)->where('stop_id', $leg['to_stop'])->first();
                if ($st1 && $st2) {
                    $t1 = strtotime($st1->departure_time);
                    $t2 = strtotime($st2->arrival_time);
                    if ($t2 < $t1)
                        $t2 += 24 * 3600;
                    $total += ($t2 - $t1) / 60;
                }
            }
        }
        return $total;
    }

    private function getBenchmarks(): array
    {
        return [
            ['type' => 'Intl', 'description' => 'Nairobi (CBD) -> Kampala', 'olat' => -1.286389, 'olng' => 36.817223, 'dlat' => 0.347596, 'dlng' => 32.582520],
            ['type' => 'Intl', 'description' => 'Nairobi (CBD) -> Dar es Salaam', 'olat' => -1.286389, 'olng' => 36.817223, 'dlat' => -6.792354, 'dlng' => 39.208328],
            ['type' => 'Intl', 'description' => 'Nairobi (CBD) -> Kigali', 'olat' => -1.286389, 'olng' => 36.817223, 'dlat' => -1.940650, 'dlng' => 30.044700],
            ['type' => 'Intl', 'description' => 'Mombasa -> Dar es Salaam', 'olat' => -4.043477, 'olng' => 39.668206, 'dlat' => -6.792354, 'dlng' => 39.208328],
            ['type' => 'Intl', 'description' => 'Kampala -> Nairobi', 'olat' => 0.347596, 'olng' => 32.582520, 'dlat' => -1.286389, 'dlng' => 36.817223],

            ['type' => 'County', 'description' => 'Nairobi -> Mombasa', 'olat' => -1.286389, 'olng' => 36.817223, 'dlat' => -4.043477, 'dlng' => 39.668206],
            ['type' => 'County', 'description' => 'Nairobi -> Kisumu', 'olat' => -1.286389, 'olng' => 36.817223, 'dlat' => -0.091702, 'dlng' => 34.767956],
            ['type' => 'County', 'description' => 'Nairobi -> Nakuru', 'olat' => -1.286389, 'olng' => 36.817223, 'dlat' => -0.303099, 'dlng' => 36.061301],
            ['type' => 'County', 'description' => 'Nairobi -> Eldoret', 'olat' => -1.286389, 'olng' => 36.817223, 'dlat' => 0.514277, 'dlng' => 35.269780],
            ['type' => 'County', 'description' => 'Nairobi -> Nyeri', 'olat' => -1.286389, 'olng' => 36.817223, 'dlat' => -0.421167, 'dlng' => 36.951458],
            ['type' => 'County', 'description' => 'Mombasa -> Nakuru', 'olat' => -4.043477, 'olng' => 39.668206, 'dlat' => -0.303099, 'dlng' => 36.061301],
            ['type' => 'County', 'description' => 'Kisumu -> Eldoret', 'olat' => -0.091702, 'olng' => 34.767956, 'dlat' => 0.514277, 'dlng' => 35.269780],
            ['type' => 'County', 'description' => 'Nairobi -> Garissa', 'olat' => -1.286389, 'olng' => 36.817223, 'dlat' => -0.483420, 'dlng' => 39.650590],
            ['type' => 'County', 'description' => 'Nairobi -> Kakamega', 'olat' => -1.286389, 'olng' => 36.817223, 'dlat' => 0.282660, 'dlng' => 34.751860],
            ['type' => 'County', 'description' => 'Nairobi -> Machakos', 'olat' => -1.286389, 'olng' => 36.817223, 'dlat' => -1.517684, 'dlng' => 37.263415],

            ['type' => 'Nairobi', 'description' => 'Westlands -> CBD', 'olat' => -1.261760, 'olng' => 36.803678, 'dlat' => -1.286389, 'dlng' => 36.817223],
            ['type' => 'Nairobi', 'description' => 'Kangemi -> CBD', 'olat' => -1.264114, 'olng' => 36.745180, 'dlat' => -1.286389, 'dlng' => 36.817223],
            ['type' => 'Nairobi', 'description' => 'Kayole -> CBD', 'olat' => -1.257650, 'olng' => 36.922490, 'dlat' => -1.286389, 'dlng' => 36.817223],
            ['type' => 'Nairobi', 'description' => 'Githurai -> CBD', 'olat' => -1.195514, 'olng' => 36.902695, 'dlat' => -1.286389, 'dlng' => 36.817223],
            ['type' => 'Nairobi', 'description' => 'Rongai -> CBD', 'olat' => -1.395274, 'olng' => 36.763639, 'dlat' => -1.286389, 'dlng' => 36.817223],
            ['type' => 'Nairobi', 'description' => 'Karen -> CBD', 'olat' => -1.322039, 'olng' => 36.707570, 'dlat' => -1.286389, 'dlng' => 36.817223],
            ['type' => 'Nairobi', 'description' => 'Langata -> CBD', 'olat' => -1.328715, 'olng' => 36.788743, 'dlat' => -1.286389, 'dlng' => 36.817223],
            ['type' => 'Nairobi', 'description' => 'South B -> CBD', 'olat' => -1.311709, 'olng' => 36.836589, 'dlat' => -1.286389, 'dlng' => 36.817223],
            ['type' => 'Nairobi', 'description' => 'Embakasi -> CBD', 'olat' => -1.310002, 'olng' => 36.914368, 'dlat' => -1.286389, 'dlng' => 36.817223],
            ['type' => 'Nairobi', 'description' => 'Westlands -> Kayole', 'olat' => -1.261760, 'olng' => 36.803678, 'dlat' => -1.257650, 'dlng' => 36.922490],
        ];
    }

    private function seedStopsWithHubs($lat, $lng, $baseCount = 3, $maxK = 10, $hubCap = 2, $totalCap = 5)
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
                        if ($p1y != $p2y)
                            $xinters = ($y - $p1y) * ($p2x - $p1x) / ($p2y - $p1y) + $p1x;
                        if ($p1x == $p2x || $x <= $xinters)
                            $inside = !$inside;
                    }
                }
            }
            $p1x = $p2x;
            $p1y = $p2y;
        }
        return $inside;
    }
}
