<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Stops;
use App\Models\Directions;
use App\Models\SaccoRoutes;
use App\Models\TransferEdge;
use App\Services\H3Wrapper;

class BuildHubs extends Command
{
    protected $signature = 'hubs:build
        {--k=3 : Hubs to keep per region}
        {--h3res=7 : Region H3 resolution if building from county centroids}
        {--walkcap=1000 : meters for counting walk neighbors}
        {--truncate : Clear transit_hubs before writing}
        {--curated= : Path to JSON file with curated hubs to reserve per region}
    ';
    protected $description = 'Score & select hub stops per region and write to transit_hubs';
    // at top of the class
private const MICRO_CLUSTER_RADIUS_M = 100;
private const CURATED_SEARCH_RADIUS_M = 350;

private array $curatedHubs = [];

// cache: h3 -> [stop_ids...]
private array $h3Index = [];
private function buildH3Index(int $res = 12): void {
    if ($this->h3Index) return;
    foreach (Stops::all(['stop_id','stop_lat','stop_long']) as $s) {
        $cell = \App\Services\H3Wrapper::latLngToCell((float)$s->stop_lat,(float)$s->stop_long,$res);
        $this->h3Index[$cell][] = (string)$s->stop_id;
    }
}
    private function microClusterCount(string $sid, int $res = 12): int {
        static $latlng=[]; if (!isset($latlng[$sid])) {
            $s = Stops::where('stop_id',$sid)->first();
            $latlng[$sid] = $s ? [(float)$s->stop_lat,(float)$s->stop_long] : [null,null];
        }
        [$lat,$lng] = $latlng[$sid]; if ($lat===null) return 0;
        $cell = \App\Services\H3Wrapper::latLngToCell($lat,$lng,$res);
        $cands = [];
        foreach (\App\Services\H3Wrapper::kRing($cell,1) as $c) {
            foreach ($this->h3Index[$c] ?? [] as $other) $cands[] = $other;
        }
        $cands = array_unique($cands);
        $cnt = 0;
        foreach ($cands as $other) {
            if ($other === $sid) continue;
            [$la,$ln] = $this->stopLL($other);
            if ($la===null) continue;
            if ($this->haversineM($lat,$lng,$la,$ln) <= self::MICRO_CLUSTER_RADIUS_M) $cnt++;
        }
        return $cnt;
    }

    private function loadCuratedHubs(?string $path): void
    {
        if (!$path) {
            return;
        }

        $resolved = $path;
        if (function_exists('base_path')) {
            $candidate = base_path($path);
            if (is_file($candidate)) {
                $resolved = $candidate;
            }
        }

        if (!is_file($resolved)) {
            $this->warn("Curated hubs file not found: {$path}");
            return;
        }

        $raw = file_get_contents($resolved);
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Failed to parse curated hubs JSON: '.json_last_error_msg());
            return;
        }

        $parsed = [];
        if (is_array($data)) {
            if ($this->isList($data)) {
                foreach ($data as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $regionId = (string)($entry['region_id'] ?? $entry['region'] ?? '');
                    if ($regionId === '') {
                        continue;
                    }
                    $stops = $entry['stop_ids'] ?? $entry['stops'] ?? [];
                    if (!is_array($stops)) {
                        $stops = [$stops];
                    }
                    $parsed[$regionId] = $this->normalizeStopList($stops, $parsed[$regionId] ?? []);
                }
            } else {
                foreach ($data as $regionId => $stops) {
                    if (!is_array($stops)) {
                        $stops = [$stops];
                    }
                    $parsed[(string)$regionId] = $this->normalizeStopList($stops, $parsed[(string)$regionId] ?? []);
                }
            }
        }

        $this->curatedHubs = $parsed;
        if ($this->curatedHubs) {
            $this->info('Loaded curated hub overrides for '.count($this->curatedHubs).' region(s).');
        }
    }

    private function normalizeStopList(array $stops, array $existing): array
    {
        $out = $existing;
        foreach ($stops as $stopId) {
            if ($stopId === null || $stopId === '') {
                continue;
            }
            $sid = (string)$stopId;
            if (!in_array($sid, $out, true)) {
                $out[] = $sid;
            }
        }
        return $out;
    }

    private function curatedStopsForRegion(string $regionId): array
    {
        return $this->curatedHubs[$regionId] ?? [];
    }

    private function isList(array $value): bool
    {
        $i = 0;
        foreach ($value as $key => $_) {
            if ($key !== $i++) {
                return false;
            }
        }
        return true;
    }


    public function handle()
    {
        $K       = (int)$this->option('k');
        $H3RES   = (int)$this->option('h3res');
        $WALKCAP = (int)$this->option('walkcap');

        $this->loadCuratedHubs($this->option('curated'));

// Build micro-cluster H3 index once
        $this->buildH3Index(12);

        if ($this->option('truncate')) {
            DB::table('transit_hubs')->truncate();
        }

        // 1) Load regions (expect them to exist already; seed them once)
        $regions = DB::table('transit_hub_regions')->get();
        if ($regions->isEmpty()) {
            $this->warn('No regions found in transit_hub_regions. Seed them first.');
            return Command::FAILURE;
        }

        // Preload route length cache (route_id -> km)
        $routeLenKm = $this->precomputeRouteLengthsKm();

        // Preload Directions index stop->routes
        $dirRoutes = Directions::all(['direction_id','direction_routes'])
            ->mapWithKeys(function ($d) {
                $r = $d->direction_routes;
                if (is_string($r)) $r = json_decode($r, true);
                return [(string)$d->direction_id => array_values($r ?? [])];
            });

        foreach ($regions as $region) {
            $this->info("Region: {$region->name}");

            // 2) Stops in region by H3 membership (fast) or polygon (fallback)
            $stopIds = $this->stopsInRegion($region, $H3RES);
            $curatedForRegion = $this->curatedStopsForRegion((string)$region->region_id);
            if ($curatedForRegion) {
                $stopIds = array_values(array_unique(array_merge($stopIds, $curatedForRegion)));
            }
            $curatedLookup = array_flip($curatedForRegion);
            if (!$stopIds) {
                $this->warn("  No stops found.");
                continue;
            }

            // 3) Score candidates
            $scored = [];
            foreach ($stopIds as $sid) {
                $routes = $dirRoutes[$sid] ?? [];
                $degree = count($routes);

                if ($degree === 0 && !isset($curatedLookup[$sid])) continue;

                $cells = [];
                $longKmSum = 0.0;
                foreach ($routes as $srid) {
                    $stops = SaccoRoutes::where('sacco_route_id',$srid)->value('stop_ids') ?: [];
                    // cross L1 cells touched
                    foreach ($stops as $tid) {
                        [$lat,$lng] = $this->stopLL($tid);
                        if ($lat !== null && $lng !== null) {
                            $cells[ H3Wrapper::latLngToCell($lat,$lng, 7) ] = true;
                        }
                    }
                    $longKmSum += ($routeLenKm[$srid] ?? 0.0);
                }
                $cross = max(0, count($cells)-1);

                $transferDeg = TransferEdge::where('from_stop_id',$sid)->get()
                    ->filter(fn($e) => $this->edgeDistanceM($e) <= $WALKCAP)
                    ->count();

               // $cbdBonus = $this->isCBDStop($sid) ? 1 : 0;

                // trips/day if available
		$tripDensity = DB::table('trips')->whereIn('sacco_route_id',$routes)->count();

		$microCluster = $this->microClusterCount($sid);

                $score = 3.0*$cross + 1.5*$degree + 1.2*($longKmSum/10.0)
			+ 0.8*$transferDeg + 0.5*$tripDensity
			+ 0.3*min(10, $microCluster);
		$metrics['micro_cluster'] = $microCluster;

                $scored[] = [
                    'stop_id'=>$sid,
                    'score'=>$score,
                    'metrics'=>[
                        'route_degree'=>$degree,
                        'cross_cells'=>$cross,
                        'long_km'=>$longKmSum,
                        'transfer_deg'=>$transferDeg,
                        'trip_density'=>$tripDensity
                    ] + ['micro_cluster' => $microCluster],
                ];
            }

            // 4) Rank & apply spacing (>= 350 m)
            usort($scored, fn($a,$b)=> $b['score'] <=> $a['score']);
            $scoredById = [];
            foreach ($scored as $cand) {
                $scoredById[$cand['stop_id']] = $cand;
            }
            $chosen = [];
            $chosenIds = [];

            foreach ($curatedForRegion as $forcedId) {
                if (isset($chosenIds[$forcedId])) {
                    continue;
                }

                [$flat,$flng] = $this->stopLL($forcedId);
                if ($flat === null || $flng === null) {
                    $this->warn("  Curated stop {$forcedId} not found in stops table; skipping.");
                    continue;
                }

                $replacement = $this->bestScoredStopNearLatLng($flat, $flng, $scored, self::CURATED_SEARCH_RADIUS_M, $chosenIds);

                if ($replacement) {
                    $cand = $replacement;
                } else {
                    $cand = $scoredById[$forcedId] ?? [
                        'stop_id' => $forcedId,
                        'score' => 0.0,
                        'metrics' => [],
                    ];
                }

                $metrics = $cand['metrics'] ?? [];
                $metrics['curated'] = true;
                if (($cand['stop_id'] ?? null) !== $forcedId) {
                    $metrics['curated_source'] = $forcedId;
                }
                $cand['metrics'] = $metrics;

                $chosen[] = $cand;
                $chosenIds[$cand['stop_id']] = true;
            }

            $targetSlots = max($K, count($chosen));
            foreach ($scored as $cand) {
                if (count($chosen) >= $targetSlots) break;
                if (isset($chosenIds[$cand['stop_id']])) continue;
                $ok = true;
                [$clat,$clng] = $this->stopLL($cand['stop_id']);
                if ($clat === null || $clng === null) continue;
                foreach ($chosen as $prev) {
                    [$plat,$plng] = $this->stopLL($prev['stop_id']);
                    if ($plat === null || $plng === null) continue;
                    if ($this->haversineM($clat,$clng,$plat,$plng) < 350) { $ok = false; break; }
                }
                if ($ok) $chosen[] = $cand;
            }

            if (count($chosenIds) > $K) {
                $this->warn('  Curated hubs exceed --k limit; writing '.count($chosen).' hubs.');
            }

            // 5) Persist
            $rank = 0;
            foreach ($chosen as $c) {
                $metrics = $c['metrics'] ?? [];
                DB::table('transit_hubs')->insert([
                    'hub_id'   => Str::uuid()->toString(),
                    'region_id'=> $region->region_id,
                    'stop_id'  => $c['stop_id'],
                    'rank'     => $rank++,
                    'score'    => $c['score'],
                    'metrics'  => json_encode($metrics),
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }

            $this->info("  Wrote ".count($chosen)." hub(s).");
        }

        $this->info('Done.');
        return Command::SUCCESS;
    }

    private function bestScoredStopNearLatLng(float $lat, float $lng, array $scored, float $radiusMeters, array $excludeIds = []): ?array
    {
        $best = null;
        foreach ($scored as $cand) {
            $cid = $cand['stop_id'] ?? null;
            if ($cid === null || isset($excludeIds[$cid])) {
                continue;
            }

            [$clat, $clng] = $this->stopLL((string)$cid);
            if ($clat === null || $clng === null) {
                continue;
            }

            if ($this->haversineM($lat, $lng, $clat, $clng) <= $radiusMeters) {
                if ($best === null || ($cand['score'] ?? 0.0) > ($best['score'] ?? 0.0)) {
                    $best = $cand;
                }
            }
        }

        return $best;
    }

    // ---------- helpers ----------
    private function stopsInRegion($region, int $res): array
    {
        $cells = [];
        if ($region->h3_cells) {
            $cells = array_map('strval', json_decode($region->h3_cells, true) ?: []);
        }

        if ($cells) {
            $cells = array_map('strval', $cells);
            $ids = [];
            foreach (Stops::all(['stop_id','stop_lat','stop_long']) as $s) {
                $c = H3Wrapper::latLngToCell((float)$s->stop_lat,(float)$s->stop_long, (int)($region->h3_res ?? $res));
                if (in_array((string)$c, $cells, true)) $ids[] = (string)$s->stop_id;
            }
            return $ids;
        }

        // fallback polygon
        if ($region->polygon) {
            $poly = json_decode($region->polygon, true) ?: [];
            // quick bbox prefilter
            $lats = array_column($poly,0); $lngs = array_column($poly,1);
            $minLat=min($lats); $maxLat=max($lats); $minLng=min($lngs); $maxLng=max($lngs);
            $stops = Stops::whereBetween('stop_lat',[$minLat,$maxLat])
                          ->whereBetween('stop_long',[$minLng,$maxLng])->get();
            $ids = [];
            foreach ($stops as $s) if ($this->pointInPoly((float)$s->stop_lat,(float)$s->stop_long,$poly)) {
                $ids[] = (string)$s->stop_id;
            }
            return $ids;
        }

        // last resort: build region from a single centroid + kRing
        $this->warn("Region {$region->name}: no h3_cells/polygon; using centroid+kRing.");
        $center = DB::table('stops')->avg('stop_lat'); // replace with your stored centroid
        // …left minimal intentionally…
        return [];
    }

    private function precomputeRouteLengthsKm(): array
    {
        $out = [];
        foreach (SaccoRoutes::all(['sacco_route_id','coordinates','stop_ids']) as $r) {
            $km = 0.0;
            $coords = $r->coordinates ?: [];
            if (is_array($coords) && count($coords) >= 2) {
                for ($i=1;$i<count($coords);$i++) {
                    $km += $this->haversineKm($coords[$i-1][0],$coords[$i-1][1],$coords[$i][0],$coords[$i][1]);
                }
            } else {
                // crow-flies between first/last stop if no polyline
                $ids = is_array($r->stop_ids)?$r->stop_ids:[];
                if (count($ids)>=2) {
                    [$lat1,$lng1] = $this->stopLL($ids[0]);
                    [$lat2,$lng2] = $this->stopLL($ids[count($ids)-1]);
                    if ($lat1!==null && $lat2!==null) $km = $this->haversineKm($lat1,$lng1,$lat2,$lng2);
                }
            }
            $out[$r->sacco_route_id] = $km;
        }
        return $out;
    }

    private array $stopLLCache=[];
    private function stopLL(string $sid): array {
        if (!isset($this->stopLLCache[$sid])) {
            $s = Stops::where('stop_id',$sid)->first();
            $this->stopLLCache[$sid] = $s ? [(float)$s->stop_lat,(float)$s->stop_long] : [null,null];
        }
        return $this->stopLLCache[$sid];
    }

    private function edgeDistanceM($edge): int
    {
        $coords = $edge->geometry ?? [];
        if (is_array($coords) && count($coords) >= 2) {
            $m = 0.0;
            for ($i=1;$i<count($coords);$i++) {
                $m += $this->haversineKm($coords[$i-1][0],$coords[$i-1][1],$coords[$i][0],$coords[$i][1]) * 1000.0;
            }
            if ($m > 1) return (int)round($m);
        }
        $sec = (int)($edge->walk_time_seconds ?? 0);
        $speed = (4.8*1000.0)/3600.0;
        return (int)round($sec * $speed);
    }

    private function pointInPoly(float $lat, float $lng, array $poly): bool
    {
        $inside=false;
        for ($i=0,$j=count($poly)-1;$i<count($poly);$j=$i++) {
            [$yi,$xi]=$poly[$i]; [$yj,$xj]=$poly[$j];
            $cross=(($yi>$lat)!=($yj>$lat)) && ($lng < ($xj-$xi)*($lat-$yi)/(($yj-$yi) ?: 1e-9)+$xi);
            if ($cross) $inside=!$inside;
        }
        return $inside;
    }

    private function haversineKm($a,$b,$c,$d){
        $R=6371.0; $dLat=deg2rad($c-$a); $dLng=deg2rad($d-$b);
        $x=sin($dLat/2)**2 + cos(deg2rad($a))*cos(deg2rad($c))*sin($dLng/2)**2;
        return 2*$R*asin(min(1.0,sqrt($x)));
    }
    private function haversineM($a,$b,$c,$d){ return $this->haversineKm($a,$b,$c,$d)*1000.0; }
}

