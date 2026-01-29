<?php
// app/Console/Commands/BuildCorridorData.php (overwrite mode, no versioning)
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Stops;
use App\Models\Directions;
use App\Models\SaccoRoutes;
use App\Services\H3Wrapper;

class BuildCorridorData extends Command
{
    protected $signature = 'corridor:build {--k=6} {--m=4}';
    protected $description = 'Build L2 stations, L1/L0 graphs into DB by overwriting existing rows';

    // Very rough CBD polygon (same one as in RoutePlannerController)
    private array $CBD_POLY = [
        [-1.2836, 36.8177],
        [-1.2878, 36.8219],
        [-1.2897, 36.8319],
        [-1.2864, 36.8346],
        [-1.2779, 36.8277],
        [-1.2799, 36.8203],
        [-1.2836, 36.8177]
    ];

    private const L2_RES_CBD = 8, L2_RES_ELSE = 7, L1_RES = 7, L0_RES = 6, BUS = 22.0;

    public function handle()
    {
        $K = (int) $this->option('k');
        $M = (int) $this->option('m');

        DB::transaction(function () use ($K, $M) {
            // Hard overwrite (keeps tables, clears contents)
            DB::statement('DELETE FROM corr_cell_edge_summaries');
            DB::statement('DELETE FROM corr_cell_portals');
            DB::statement('DELETE FROM corr_cell_neighbors');
            DB::statement('DELETE FROM corr_cells');
            DB::statement('DELETE FROM corr_station_members');
            DB::statement('DELETE FROM corr_stations');

            // STATIONS
            [$stations, $members] = $this->buildStations();
            // route degree per station
            $dirRoutes = $this->loadDirRoutes();
            foreach ($stations as $sid => &$sm) {
                $deg = 0;
                foreach ($sm['members'] as $stopId)
                    $deg += count($dirRoutes[$stopId] ?? []);
                $sm['deg'] = $deg;
            }unset($sm);

            // write stations + members
            foreach ($stations as $sid => $sm) {
                DB::table('corr_stations')->insert([
                    'station_id' => $sid,
                    'lat' => $sm['center'][0],
                    'lng' => $sm['center'][1],
                    'l1_cell' => $sm['l1'],
                    'l0_cell' => $sm['l0'],
                    'route_degree' => $sm['deg'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                foreach ($sm['members'] as $stopId) {
                    DB::table('corr_station_members')->insert([
                        'station_id' => $sid,
                        'stop_id' => $stopId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // CELLS (L1/L0) + neighbors
            [$l1Cells, $l0Cells] = $this->collectCells($stations);
            foreach ($l1Cells as $id => $c) {
                DB::table('corr_cells')->insert([
                    'cell_id' => $id,
                    'level' => 1,
                    'lat' => $c['lat'],
                    'lng' => $c['lng'],
                    'l0_parent' => $c['l0_parent'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                foreach ($c['nb'] as $n) {
                    DB::table('corr_cell_neighbors')->insert([
                        'level' => 1,
                        'cell_a' => $id,
                        'cell_b' => $n,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            foreach ($l0Cells as $id => $c) {
                DB::table('corr_cells')->insert([
                    'cell_id' => $id,
                    'level' => 0,
                    'lat' => $c['lat'],
                    'lng' => $c['lng'],
                    'l0_parent' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                foreach ($c['nb'] as $n) {
                    DB::table('corr_cell_neighbors')->insert([
                        'level' => 0,
                        'cell_a' => $id,
                        'cell_b' => $n,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // PORTALS (score & top-K) for L1 and L0
            $portalL1 = $this->selectPortals($stations, $dirRoutes, 1, $K);
            $portalL0 = $this->selectPortals($stations, $dirRoutes, 0, $K);

            foreach ($portalL1 as $cell => $rows) {
                $rank = 0;
                foreach ($rows as $row) {
                    DB::table('corr_cell_portals')->insert([
                        'level' => 1,
                        'cell_id' => $cell,
                        'station_id' => $row['sid'],
                        'score' => $row['score'],
                        'rank' => $rank++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            foreach ($portalL0 as $cell => $rows) {
                $rank = 0;
                foreach ($rows as $row) {
                    DB::table('corr_cell_portals')->insert([
                        'level' => 0,
                        'cell_id' => $cell,
                        'station_id' => $row['sid'],
                        'score' => $row['score'],
                        'rank' => $rank++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // EDGE SUMMARIES (top-M portal pairs) for neighbors
            $this->buildEdgeSummaries(1, $M);
            $this->buildEdgeSummaries(0, $M);
        });

        $this->info('Corridor graph built (overwrite mode).');

        // Clear StationRaptor cache so new data is picked up immediately
        \Illuminate\Support\Facades\Cache::forget('station_raptor_data_v1');
        $this->info('StationRaptor cache cleared.');
    }

    // ---- helpers (cluster stations, routes per stop, cells, portals, edges) ----
    private function buildStations(): array
    {
        $stops = Stops::all(['stop_id', 'stop_lat', 'stop_long']);
        $stations = [];
        $members = [];
        foreach ($stops as $s) {
            $lat = (float) $s->stop_lat;
            $lng = (float) $s->stop_long;
            $res = $this->pointInPoly($lat, $lng, $this->CBD_POLY) ? self::L2_RES_CBD : self::L2_RES_ELSE;
            $cell = H3Wrapper::latLngToCell($lat, $lng, $res);
            $sid = "st:$res:$cell";
            $stations[$sid]['members'][] = (string) $s->stop_id;
            $stations[$sid]['sum'][0] = ($stations[$sid]['sum'][0] ?? 0) + $lat;
            $stations[$sid]['sum'][1] = ($stations[$sid]['sum'][1] ?? 0) + $lng;
            $stations[$sid]['cnt'] = ($stations[$sid]['cnt'] ?? 0) + 1;
            $stations[$sid]['l1'] = H3Wrapper::latLngToCell($lat, $lng, self::L1_RES);
            $stations[$sid]['l0'] = H3Wrapper::latLngToCell($lat, $lng, self::L0_RES);
        }
        foreach ($stations as $sid => &$sm) {
            $sm['center'] = [$sm['sum'][0] / $sm['cnt'], $sm['sum'][1] / $sm['cnt']];
        }
        unset($sm);
        return [$stations, null];
    }

    private function loadDirRoutes(): array
    {
        $out = [];
        foreach (Directions::all(['direction_id', 'direction_routes']) as $d) {
            $r = $d->direction_routes;
            if (is_string($r))
                $r = json_decode($r, true);
            $out[(string) $d->direction_id] = array_values($r ?? []);
        }
        return $out;
    }

    private function collectCells(array $stations): array
    {
        $l1 = [];
        $l0 = [];
        $seen1 = [];
        $seen0 = [];
        foreach ($stations as $sid => $sm) {
            $c1 = $sm['l1'];
            $c0 = $sm['l0'];
            if (!isset($seen1[$c1])) {
                $seen1[$c1] = true;
                [$lat, $lng] = $sm['center'];
                $l1[$c1] = ['lat' => $lat, 'lng' => $lng, 'nb' => $this->presentNeighbors($c1, self::L1_RES, $seen1), 'l0_parent' => $sm['l0']];
            }
            if (!isset($seen0[$c0])) {
                $seen0[$c0] = true;
                [$lat, $lng] = $sm['center'];
                $l0[$c0] = ['lat' => $lat, 'lng' => $lng, 'nb' => $this->presentNeighbors($c0, self::L0_RES, $seen0)];
            }
        }
        return [$l1, $l0];
    }

    private function presentNeighbors(string $cell, int $res, array $present): array
    {
        $out = [];
        foreach (H3Wrapper::kRing($cell, 1) as $n)
            if ($n !== $cell && isset($present[$n]))
                $out[] = $n;
        return $out;
    }

    private function selectPortals(array $stations, array $dirRoutes, int $level, int $K): array
    {
        // pre-index: stop -> station, station -> cell(level)
        $stopToStation = [];
        $stationCell = [];
        foreach ($stations as $sid => $sm) {
            $cell = $level === 1 ? $sm['l1'] : $sm['l0'];
            $stationCell[$sid] = $cell;
            foreach ($sm['members'] as $stopId)
                $stopToStation[$stopId] = $sid;
        }

        // Build cross-cell counts per station using the routes’ stop lists
        $crossRoutesCnt = [];  // sid => routes crossing
        $neighborCover = [];  // sid => distinct neighbor cells covered
        foreach ($stations as $sid => $_) {
            $crossRoutesCnt[$sid] = 0;
            $neighborCover[$sid] = [];
        }

        foreach (SaccoRoutes::all(['sacco_route_id', 'stop_ids']) as $route) {
            $stops = is_array($route->stop_ids) ? $route->stop_ids : [];
            $cells = []; // set of cells touched by this route (at this level)
            $sidHit = []; // stations that belong to those cells

            foreach ($stops as $stopId) {
                $sid = $stopToStation[$stopId] ?? null;
                if (!$sid)
                    continue;
                $cell = $stationCell[$sid] ?? null;
                if (!$cell)
                    continue;
                $cells[$cell] = true;
                $sidHit[$sid] = true;
            }
            if (count($cells) <= 1)
                continue; // route stays inside cell → not cross-cell

            // This route leaves some cells; reward the stations it touches in those cells
            $cellList = array_keys($cells);
            foreach ($sidHit as $sid => $_) {
                $crossRoutesCnt[$sid]++;
                foreach ($cellList as $c)
                    $neighborCover[$sid][$c] = true;
            }
        }

        // Score per station
        $byCell = []; // cell => [ {sid, score, deg}, ... ]
        foreach ($stations as $sid => $sm) {
            $cell = $stationCell[$sid];
            $deg = $sm['deg'] ?? 0;
            $cross = $crossRoutesCnt[$sid] ?? 0;
            $nbrs = max(0, count($neighborCover[$sid] ?? []) - 1); // exclude own cell
            $walkC = 0; // placeholder for future walk graph degree
            $hubBonus = 0; // placeholder for curated hubs

            $score = 3.0 * $cross + 1.5 * $nbrs + 0.5 * $deg + 0.25 * $walkC + $hubBonus;
            $byCell[$cell][] = ['sid' => $sid, 'score' => $score, 'deg' => $deg];
        }

        $result = [];
        foreach ($byCell as $cell => $rows) {
            usort($rows, fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($b['deg'] <=> $a['deg']));
            $result[$cell] = array_slice($rows, 0, $K);
        }
        return $result;
    }

    private function buildEdgeSummaries(int $level, int $M): void
    {
        $neighbors = DB::table('corr_cell_neighbors')->where('level', $level)->get();
        $cache = [];

        foreach ($neighbors as $e) {
            $A = $e->cell_a;
            $B = $e->cell_b;

            $portalsA = DB::table('corr_cell_portals')
                ->where('level', $level)->where('cell_id', $A)
                ->orderBy('rank')->pluck('station_id')->all();

            $portalsB = DB::table('corr_cell_portals')
                ->where('level', $level)->where('cell_id', $B)
                ->orderBy('rank')->pluck('station_id')->all();

            if (!$portalsA || !$portalsB)
                continue;

            // cross all portal pairs and keep top M by crow-flies
            $pairs = [];
            foreach ($portalsA as $sa) {
                [$la, $lga] = $this->coord($sa, $cache);
                foreach ($portalsB as $sb) {
                    [$lb, $lgb] = $this->coord($sb, $cache);
                    $km = $this->haversineKm($la, $lga, $lb, $lgb);
                    $min = ($km / self::BUS) * 60.0;
                    $pairs[] = ['from' => $sa, 'to' => $sb, 'minutes' => $min];
                }
            }
            usort($pairs, fn($x, $y) => $x['minutes'] <=> $y['minutes']);
            $pairs = array_slice($pairs, 0, $M);

            $rank = 0;
            foreach ($pairs as $p) {
                DB::table('corr_cell_edge_summaries')->insert([
                    'level' => $level,
                    'from_cell' => $A,
                    'to_cell' => $B,
                    'from_station' => $p['from'],
                    'to_station' => $p['to'],
                    'minutes' => $p['minutes'],
                    'rank' => $rank++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function coord(string $sid, array &$cache): array
    {
        if (!isset($cache[$sid])) {
            $row = DB::table('corr_stations')->where('station_id', $sid)->first(['lat', 'lng']);
            $cache[$sid] = [$row->lat, $row->lng];
        }
        return $cache[$sid];
    }

    private function haversineKm($a, $b, $c, $d)
    {
        $R = 6371.0;
        $dLat = deg2rad($c - $a);
        $dLng = deg2rad($d - $b);
        $x = sin($dLat / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin($dLng / 2) ** 2;
        return 2 * $R * asin(min(1.0, sqrt($x)));
    }

    /**
     * Point-in-polygon test (ray casting). Returns true if (lat,lng) lies inside $poly.
     * $poly is an array of [lat, lng] pairs. Works for convex or concave simple polygons.
     */
    private function pointInPoly(float $lat, float $lng, array $poly): bool
    {
        $inside = false;
        $n = count($poly);
        if ($n < 3)
            return false;

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$yi, $xi] = $poly[$i]; // note: stored as [lat, lng] but we compare on lng for ray cast
            [$yj, $xj] = $poly[$j];

            // Check if the horizontal ray to the right from (lat,lng) crosses edge (i,j)
            $intersect = (($xi > $lng) != ($xj > $lng)) &&
                ($lat < ($yj - $yi) * ($lng - $xi) / (($xj - $xi) ?: 1e-9) + $yi);

            if ($intersect)
                $inside = !$inside;
        }
        return $inside;
    }
}

