<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\H3Wrapper;
use App\Models\Directions;
use App\Models\TransferEdge;
use App\Models\Stops;
use App\Models\SaccoRoutes;
use App\Models\Variation;
use App\Models\Trip;
use Carbon\Carbon;

// NEW
use App\Services\BusTravelTimeService;
use App\Services\CorridorGraph;
use App\Services\CorridorPlanner;
use App\Services\FareCalculator;
use App\Services\WalkRouter;


use App\Services\StationRaptor; // NEW

class RoutePlannerController extends Controller
{
    // === Tunables for A* ===
    private const BUS_SPEED_KMPH = 22.0; // optimistic to keep heuristic admissible
    private const WALK_SPEED_KMPH = 3;
    private const WALK_WEIGHT = 1.0;  // walking is perceived as longer
    private const TRANSFER_PENALTY = 10.0;  // minutes equivalent per transfer

    // How many goal paths A* should collect before stopping
    private const MAX_CANDIDATES = 30;

    // === RAPTOR-lite tunables (no DB changes needed) ===
    private const MAX_ROUNDS = 3;     // up to 2 transfers
    private const DEADLINE_SEC = 10.0;   // anytime ceiling
    private const CBD_MIN_M = 450;   // min bus leg length in CBD
    private const NONCBD_MIN_M = 550;   // min bus leg length elsewhere
    private const JUMP_K = 10;     // how many transfer stops to jump to per scan
    private const WALK_CAP_M = 1500;   // max walk to consider a transfer candidate
    private const ACCESS_EGRESS_CAP_M = 5000; // cap access/egress walks in metres

    // Very rough CBD polygon (replace with better outline when convenient)
    private array $CBD_POLY = [
        [-1.2819192, 36.8151016],
        [-1.2881855, 36.8181559],
        [-1.2958690, 36.8219236],
        [-1.2875517, 36.8376109],
        [-1.2811049, 36.8339069],
        [-1.2773586, 36.8291844],
        [-1.2788849, 36.8213368]
    ];

    // Optional hub preference
    private array $hubStops = [];

    // === Light caches (per request) ===
    private array $routeCache = [];     // sacco_route_id => SaccoRoutes (partial)
    private array $routeStops = [];     // sacco_route_id => [stop_ids...]
    private array $stopLL = [];     // stop_id => [lat, lng]
    private array $walkAdj = [];     // from_stop_id => [ ['to'=>id, 'sec'=>int], ... ]
    private array $routesByStop = [];   // stop_id => [sacco_route_id...]
    private array $routeTrips = [];   // sacco_route_id => Trip collection

    // RAPTOR-lite on-the-fly caches
    private array $cumKmCache = []; // srid => [cum_km...]
    private array $nextTransfersCache = []; // srid => [ "idx" => [next idx...] ]
    private array $routesAtStopCache = []; // stop_id => [srids...]
    private array $walkFromCache = []; // stop_id => [TransferEdge-like arrays]
    private array $isCbdStopCache = []; // stop_id => bool

    // Corridor whitelist (set per query)
    private ?array $corridorAllowedStops = null; // stop_id => true
    private ?WalkRouter $walkRouter = null;
    private FareCalculator $fareCalculator;
    private BusTravelTimeService $busTravelTimeService;
    private StationRaptor $stationRaptor; // NEW
    private bool $isEventDay = false;

    public function __construct(
        WalkRouter $walkRouter,
        FareCalculator $fareCalculator,
        BusTravelTimeService $busTravelTimeService,
        StationRaptor $stationRaptor // NEW
    ) {
        $this->walkRouter = $walkRouter;
        $this->fareCalculator = $fareCalculator;
        $this->busTravelTimeService = $busTravelTimeService;
        $this->stationRaptor = $stationRaptor;
    }
    public function index()
    {
        return response()->json(['message' => 'Route Planner API is ready'], 200);
    }

    public function multilegRoute(Request $request)
    {
        \Log::info('Multileg route request received', ['request' => $request->all()]);

        // Accept both old query param style and new JSON payload
        $labelRules = [
            'origin_label' => 'nullable|string|max:255',
            'destination_label' => 'nullable|string|max:255',
            'result_summary' => 'nullable|array',
            'preferred_saccos' => 'nullable|array',
            'preferred_saccos.*' => 'string',
        ];

        if ($request->has(['start_lat', 'start_lng', 'end_lat', 'end_lng'])) {
            $data = $request->validate([
                'start_lat' => 'required|numeric',
                'start_lng' => 'required|numeric',
                'end_lat' => 'required|numeric',
                'end_lng' => 'required|numeric',
            ] + $labelRules);
            $olat = (float) $data['start_lat'];
            $olng = (float) $data['start_lng'];
            $dlat = (float) $data['end_lat'];
            $dlng = (float) $data['end_lng'];
        } else {
            $data = $request->validate([
                'origin' => 'required|array|size:2',
                'origin.0' => 'numeric',
                'origin.1' => 'numeric',
                'destination' => 'required|array|size:2',
                'destination.0' => 'numeric',
                'destination.1' => 'numeric',
            ] + $labelRules);
            [$olat, $olng] = $data['origin'];
            [$dlat, $dlng] = $data['destination'];
        }

        if (!$request->has('origin')) {
            $request->merge(['origin' => [$olat, $olng]]);
        }

        if (!$request->has('destination')) {
            $request->merge(['destination' => [$dlat, $dlng]]);
        }

        $includeWalking = $request->boolean('include_walking', true);

        $departAfterInput = $request->input('depart_after');
        if ($departAfterInput !== null) {
            $request->validate(['depart_after' => 'date']);
        }
        $departAfter = $this->resolveDepartAfter($departAfterInput);
        $odKm = $this->haversineKm($olat, $olng, $dlat, $dlng);
        $odKm = $this->haversineKm($olat, $olng, $dlat, $dlng);

        // NEW: per-request global hub set (CBD + regional hubs)
        $this->hubStops = $this->loadHubStopsForTrip($odKm);

        // Access/egress stops (same as before)
        // $originStops = $this->nearestStops($olat, $olng);
        // $destStops   = $this->nearestStops($dlat, $dlng);

        // Access/egress stops (nearest + regional hubs, de-duped, capped)
        $originStops = $this->seedStopsWithHubs($olat, $olng, 3, 6, 2, 5); //baseCount, maxK, hubCap, tottalCap
        $destStops = $this->seedStopsWithHubs($dlat, $dlng, 3, 6, 2, 5);


        // --- NEW: Station-Based RAPTOR ---
        \Log::info("Starting Station-Based RAPTOR...");
        $this->stationRaptor->loadData();
        \Log::info("Station Data Loaded.");

        $rawPaths = [];
        $oStop = $originStops->first();
        $dStop = $destStops->first();

        \Log::info("Origin: " . ($oStop['stop_id'] ?? 'NULL') . ", Dest: " . ($dStop['stop_id'] ?? 'NULL'));

        if ($oStop && $dStop) {
            $results = $this->stationRaptor->search($oStop['stop_id'], $dStop['stop_id']);
            \Log::info("Search Results: " . (isset($results['error']) ? $results['error'] : count($results) . " paths"));

            if (!isset($results['error'])) {
                foreach ($results as $path) {
                    // Expand
                    $detailed = $this->stationRaptor->expandPath($path, $oStop['stop_id'], $dStop['stop_id']);

                    if (empty($detailed))
                        continue; // Skip failed expansions

                    // Convert to Raw Path format
                    $converted = [];
                    $converted[] = ['stop_id' => $oStop['stop_id'], 'label' => 'start'];

                    $lastStop = $oStop['stop_id'];
                    $valid = true;

                    foreach ($detailed as $leg) {
                        if (!$leg['walk_valid']) {
                            $valid = false;
                            break;
                        }

                        // Safety check for null stops
                        if (!$leg['from_stop'] || !$leg['to_stop']) {
                            $valid = false;
                            break;
                        }

                        if ($leg['from_stop'] !== $lastStop) {
                            // Walk leg
                            $converted[] = ['stop_id' => $leg['from_stop'], 'label' => 'walk 5 min'];
                        }

                        // Bus leg
                        $converted[] = ['stop_id' => $leg['to_stop'], 'label' => 'bus via ' . $leg['route_id']];
                        $lastStop = $leg['to_stop'];
                    }

                    if ($valid) {
                        $rawPaths[] = $converted;
                    }
                }
            }
        }
        \Log::info("Raw Paths Constructed: " . count($rawPaths));

        /*
        // --- OLD: Hierarchical corridor gating with widen-once fallback ---
        $graph = new CorridorGraph();
        $planner = new CorridorPlanner($graph);

        // Build initial whitelist
        $plan = $planner->buildWhitelist($olat, $olng, $dlat, $dlng, false);
        $this->applyCorridorWhitelist($plan, $originStops->toArray(), $destStops->toArray());

        $this->isEventDay = $request->boolean('is_event_day', false);

        $rawPaths = $this->raptorLiteRoute(
            $originStops->toArray(),
            $destStops->toArray(),
            $includeWalking,
            $departAfter,
            $this->isEventDay,
            $dlat,
            $dlng,
            $olat,
            $olng,
            $odKm
        );

        // ... (rest of old logic commented out)
        */

        $this->clearCorridorWhitelist();

        if (!$rawPaths) {
            return response()->json(['message' => 'No route found', 'routes' => []], 200);
        }
        $enriched = array_map(fn($p) => $this->enrichPath($p, $departAfter, $this->isEventDay), $rawPaths);
        // NEW: collapse tiny CBD bus hops into walks (and re-merge)
        $enriched = array_map(fn($e) => $this->collapseCbdHops($e, 750), $enriched);
        $enriched = array_map(fn($e) => $this->collapseUrbanRun($e, 1100), $enriched);

        $unique = $this->dedupeMultiLeg($enriched);
        // Only slice to top 12 AFTER dedupe, not before
        $unique = array_slice($unique, 0, self::MAX_CANDIDATES);
        // Add access + egress walking legs
        $withBookends = array_values(array_filter(array_map(function ($it) use ($olat, $olng, $dlat, $dlng) {
            $legs = $it['legs'] ?? [];
            if (!$legs)
                return $it;

            $accessCapped = false;
            $egressCapped = false;

            $first = $this->buildAccessWalk($legs[0], $olat, $olng, $accessCapped);
            $last = $this->buildEgressWalk($legs[count($legs) - 1], $dlat, $dlng, $egressCapped);

            if ($accessCapped || $egressCapped) {
                return null;
            }

            $out = [];
            if ($first)
                $out[] = $first;
            $out = array_merge($out, $legs);
            if ($last)
                $out[] = $last;

            $it['legs'] = $out;
            return $it;
        }, $unique), fn($it) => $it !== null));

        $withSummary = array_map(function ($it) {
            $legs = $it['legs'] ?? [];
            $total = 0.0;

            foreach ($legs as $leg) {
                $mode = $leg['mode'] ?? null;
                if ($mode === 'bus' && isset($leg['duration_minutes']) && is_numeric($leg['duration_minutes'])) {
                    $total += (float) $leg['duration_minutes'];
                } elseif ($mode === 'walk' && isset($leg['minutes']) && is_numeric($leg['minutes'])) {
                    $total += (float) $leg['minutes'];
                }
            }

            $summary = $it['summary'] ?? [];
            $summary['total_duration_minutes'] = round($total, 1);
            $it['summary'] = $summary;

            return $it;
        }, $withBookends);

        // --- NEW: Enrich Top 3 with Traffic Data ---
        // We only take the first 3 candidates to save API costs
        $topCandidates = array_slice($withSummary, 0, 3);
        $remainingCandidates = array_slice($withSummary, 3);

        $enrichedTop = array_map(function ($it) {
            if (!empty($it['legs'])) {
                $it['legs'] = $this->busTravelTimeService->enrichWithTraffic($it['legs']);
            }
            return $it;
        }, $topCandidates);

        $finalResults = array_merge($enrichedTop, $remainingCandidates);

        // --- NEW: Diversify Results ---
        $preferredSaccos = $request->input('preferred_saccos', []);
        $finalResults = $this->diversifyResults($finalResults, $preferredSaccos);

        // --- NEW: Re-rank after diversification ---
        usort($finalResults, function ($a, $b) {
            $costA = $this->totalCostFromEnriched($a);
            $costB = $this->totalCostFromEnriched($b);
            return $costA <=> $costB;
        });

        return response()->json(['single_leg' => [], 'multi_leg' => $finalResults]);

    }

    // AFTER:
    private function resolveDepartAfter(?string $value): Carbon
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value, 'Africa/Nairobi')->setTimezone('Africa/Nairobi');
            } catch (\Throwable $e) {
                // fall through to now()
            }
        }

        return Carbon::now('Africa/Nairobi');
    }

    private function applyCorridorWhitelist(array $plan, array $originStops, array $destStops): void
    {
        $allowedStops = $plan['allowedStops'] ?? [];

        // (A) allow corridor L1/L0 cells if you’re using cell whitelist
        if (!$allowedStops && !empty($plan['allowedCellsRes9'] ?? [])) {
            $cells = array_map('strval', $plan['allowedCellsRes9']);
            $allowedStops = Directions::whereIn('h3_index', $cells)->pluck('direction_id')->toArray();
        }

        // (B) always allow seeds (access/egress)
        foreach ($originStops as $s) {
            $allowedStops[] = (string) $s['stop_id'];
        }
        foreach ($destStops as $s) {
            $allowedStops[] = (string) $s['stop_id'];
        }

        // (C) add hubs near origin/dest
        foreach ([$originStops, $destStops] as $side) {
            foreach ($side as $s) {
                $nearHubs = $this->lookupTopHubsForPoint((float) $s['stop_lat'], (float) $s['stop_long'], 3);
                foreach ($nearHubs as $h)
                    $allowedStops[] = (string) $h['stop_id'];
            }
        }

        // (D) add corridor portals (and their station members)
        $portals = $this->corridorPortalsAlongPlan($plan);      // implement: pull top-K portals for cells in plan
        foreach ($portals as $stationId) {
            foreach ($this->stationMembers($stationId) as $sid) {
                $allowedStops[] = (string) $sid;
            }
        }

        // (E)ONLY if trip starts/ends in CBD, thin CBD gateways (optional)
        $origin = $originStops[0] ?? null;
        $dest = $destStops[0] ?? null;
        $originInCBD = $origin ? $this->pointInPoly((float) $origin['stop_lat'], (float) $origin['stop_long'], $this->CBD_POLY) : false;
        $destInCBD = $dest ? $this->pointInPoly((float) $dest['stop_lat'], (float) $dest['stop_long'], $this->CBD_POLY) : false;
        if ($originInCBD || $destInCBD) {
            $allowedStops = array_merge($allowedStops, $this->topCbdStopsByDegree(12));
        }

        $allowedStops = array_values(array_unique(array_map('strval', $allowedStops)));
        $this->corridorAllowedStops = $allowedStops ? array_fill_keys($allowedStops, true) : null;
    }

    private function clearCorridorWhitelist(): void
    {
        $this->corridorAllowedStops = null;
    }

    // --------------------------
    // Existing helpers (unchanged below except where noted)
    // --------------------------

    private function nearestStops($lat, $lng, $count = 3, $maxK = 6)
    {
        $index = H3Wrapper::latLngToCell($lat, $lng, 9);
        \Log::debug('H3 cell computed', ['lat' => $lat, 'lng' => $lng, 'cell' => $index]);

        $expr = '(6371000 * acos(cos(radians(?)) * cos(radians(direction_latitude)) * ' .
            'cos(radians(direction_longitude) - radians(?)) + sin(radians(?)) * ' .
            'sin(radians(direction_latitude))))';

        $picked = collect();

        // Expand rings until we have 3 unique stops (or hit maxK)
        for ($k = 0; $k <= $maxK && $picked->count() < $count; $k++) {
            $cells = array_map('strval', H3Wrapper::kRing($index, $k));     // ← cast to string
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

        // Geodistance fallback if rings were too sparse
        if ($picked->count() < $count) {
            $fallback = Directions::with('stop')
                ->selectRaw("*, {$expr} AS distance", [$lat, $lng, $lat])
                ->orderBy('distance')
                ->limit($count * 5)
                ->get()
                ->filter(fn($d) => $d->stop !== null);

            $picked = $picked->merge($fallback)
                ->unique(fn($d) => $d->stop->stop_id)
                ->sortBy('distance')
                ->take($count);

            \Log::warning('nearestStops() fell back to pure geodistance', [
                'lat' => $lat,
                'lng' => $lng,
                'found' => $picked->count()
            ]);
        }

        \Log::info('nearestStops() returned', [
            'lat' => $lat,
            'lng' => $lng,
            'stops' => $picked->pluck('stop.stop_id'),
        ]);

        return $picked->map(fn($d) => [
            'stop_id' => $d->stop->stop_id,
            'stop_name' => $d->stop->stop_name,
            'stop_lat' => $d->stop->stop_lat,
            'stop_long' => $d->stop->stop_long,
        ])->values();
    }

    private function stopInfo(string $directionId)
    {
        return Stops::where('stop_id', $directionId)->first();
    }

    private function buildBusLeg(
        $saccoRouteId,
        $fromDir,
        $toDir,
        $trip = null,
        ?float $distanceKm = null,
        ?Carbon $departAfter = null,
        bool $isEventDay = false
    ) {
        $route = SaccoRoutes::with(['sacco', 'route', 'variations'])
            ->where('sacco_route_id', $saccoRouteId)
            ->first();
        if (!$route) {
            return null;
        }

        $board = $this->stopInfo($fromDir);
        $alight = $this->stopInfo($toDir);
        if (!$board || !$alight)
            return null;

        $bLat = (float) $board->stop_lat;
        $bLng = (float) $board->stop_long;
        $aLat = (float) $alight->stop_lat;
        $aLng = (float) $alight->stop_long;

        $coords = [];
        $variations = [];
        foreach ($route->variations as $var) {
            $stops = $var->stop_ids ?: [];
            $sIdx = array_search($fromDir, $stops, true);
            $eIdx = array_search($toDir, $stops, true);
            if ($sIdx !== false && $eIdx !== false && $sIdx <= $eIdx) {
                $segment = array_slice($var->coordinates ?: [], $sIdx, $eIdx - $sIdx + 1);
                $variations[] = $segment;
                if (!$coords)
                    $coords = $segment;
            }
        }

        if (!$coords) {
            $startIdx = null;
            $bestB = INF;
            $endIdx = null;
            $bestA = INF;
            foreach ($route->coordinates ?? [] as $i => [$lat, $lng]) {
                $dB = ($lat - $bLat) ** 2 + ($lng - $bLng) ** 2;
                if ($dB < $bestB) {
                    $bestB = $dB;
                    $startIdx = $i;
                }
                $dA = ($lat - $aLat) ** 2 + ($lng - $aLng) ** 2;
                if ($dA < $bestA) {
                    $bestA = $dA;
                    $endIdx = $i;
                }
            }
            if ($startIdx !== null && $endIdx !== null && $startIdx <= $endIdx) {
                $coords = array_slice($route->coordinates ?: [], $startIdx, $endIdx - $startIdx + 1);
            } else {
                $coords = $route->coordinates ?: [];
            }
        }

        if ($distanceKm === null) {
            $distanceKm = $this->haversineKm($bLat, $bLng, $aLat, $aLng);
        }

        $routeStopIds = $this->getRouteStops($route->sacco_route_id) ?: ($route->stop_ids ?? []);
        $totalRouteDistanceKm = $this->routeDistanceKm($routeStopIds);

        $fareBreakdown = $this->fareCalculator->calculate(
            $distanceKm,
            $totalRouteDistanceKm,
            $departAfter,
            $isEventDay,
            $route->peak_fare,
            $route->off_peak_fare,
            $this->isCBDStopOnTheFly($fromDir),
            $this->isCBDStopOnTheFly($toDir)
        );

        if (!$trip && $departAfter) {
            $trip = Trip::where('sacco_route_id', $saccoRouteId)
                ->where('start_time', '>=', $departAfter->format('H:i:s'))
                ->orderBy('start_time')
                ->first();
        }

        $leg = [
            'mode' => 'bus',
            'sacco_route_id' => $route->sacco_route_id,
            'route_id' => $route->route_id,
            'sacco_name' => $route->sacco->sacco_name ?? '',
            'route_number' => $route->route->route_number ?? '',
            'route_name' => ($route->route->route_start_stop ?? '') . ' - ' . ($route->route->route_end_stop ?? ''),
            'currency' => $route->currency ?? 'KES',
            'board_stop' => [
                'stop_id' => $board->stop_id,
                'stop_name' => $board->stop_name,
                'lat' => (float) $board->stop_lat,
                'lng' => (float) $board->stop_long,
            ],
            'alight_stop' => [
                'stop_id' => $alight->stop_id,
                'stop_name' => $alight->stop_name,
                'lat' => (float) $alight->stop_lat,
                'lng' => (float) $alight->stop_long,
            ],
            'fare' => (float) $fareBreakdown['fare'],
            'peak_fare' => (float) $fareBreakdown['peak_fare'],
            'off_peak_fare' => (float) $fareBreakdown['off_peak_fare'],
            'distance_km' => (float) $fareBreakdown['distance_km'],
            'requires_manual_fare' => (bool) $fareBreakdown['requires_manual_fare'],
            'coordinates' => $coords,
            'variations' => $variations,
            'has_variations' => (bool) ($route->has_variations && !empty($variations)),
            'trip' => $trip,
        ];

        $duration = $this->busTravelTimeService->estimate(
            [
                'stop_id' => $leg['board_stop']['stop_id'],
                'lat' => $leg['board_stop']['lat'],
                'lng' => $leg['board_stop']['lng'],
            ],
            [
                'stop_id' => $leg['alight_stop']['stop_id'],
                'lat' => $leg['alight_stop']['lat'],
                'lng' => $leg['alight_stop']['lng'],
            ],
            $coords,
            null,
            fn() => $this->estimateBusMinutesForLeg($leg)
        );

        $leg['duration_minutes'] = (float) ($duration['minutes'] ?? 0.0);
        if (!empty($duration['source'])) {
            $leg['duration_source'] = $duration['source'];
        }

        return $leg;
    }

    private function buildWalkLeg($fromDir, $toDir, $minutes)
    {
        $from = $this->stopInfo($fromDir);
        $to = $this->stopInfo($toDir);
        if (!$from || !$to)
            return null;

        $edge = TransferEdge::where('from_stop_id', $fromDir)
            ->where('to_stop_id', $toDir)
            ->first();

        $coords = $edge?->geometry ?? [];

        if (!$coords && $this->walkRouter) {
            $r = $this->walkRouter->route(
                (float) $from->stop_lat,
                (float) $from->stop_long,
                (float) $to->stop_lat,
                (float) $to->stop_long
            );
            if ($r) {
                $coords = $r['coords'];
                $minutes = (int) ceil(($r['duration_s'] ?? $minutes * 60) / 60);

                // persist for next time (creates edge if missing)
                if ($edge) {
                    $edge->geometry = $coords;
                    if (empty($edge->walk_time_seconds) && !empty($r['duration_s'])) {
                        $edge->walk_time_seconds = $r['duration_s'];
                    }
                    $edge->save();
                } else {
                    TransferEdge::create([
                        'from_stop_id' => $fromDir,
                        'to_stop_id' => $toDir,
                        'walk_time_seconds' => $r['duration_s'] ?? ($minutes * 60),
                        'geometry' => $coords,
                    ]);
                }
            }
        }

        return [
            'mode' => 'walk',
            'minutes' => (int) $minutes,
            'from_stop' => [
                'stop_id' => $from->stop_id,
                'stop_name' => $from->stop_name,
                'lat' => (float) $from->stop_lat,
                'lng' => (float) $from->stop_long,
            ],
            'to_stop' => [
                'stop_id' => $to->stop_id,
                'stop_name' => $to->stop_name,
                'lat' => (float) $to->stop_lat,
                'lng' => (float) $to->stop_long,
            ],
            'coordinates' => $coords,
        ];
    }

    private function enrichPath(array $path, ?Carbon $departAfter = null, bool $isEventDay = false)
    {
        $legs = [];
        $hasVariations = false;
        for ($i = 1; $i < count($path); $i++) {
            $prev = $path[$i - 1];
            $step = $path[$i];

            if (str_starts_with($step['label'], 'bus via ')) {
                $saccoRouteId = substr($step['label'], 8);
                $distanceKm = isset($step['km']) ? (float) $step['km'] : null;
                $leg = $this->buildBusLeg(
                    $saccoRouteId,
                    $prev['stop_id'],
                    $step['stop_id'],
                    $step['trip'] ?? null,
                    $distanceKm,
                    $departAfter,
                    $isEventDay
                );
                if ($leg) {
                    if ($leg['has_variations']) {
                        $hasVariations = true;
                    }
                    $legs[] = $leg;
                }
            } elseif (preg_match('/walk (\d+) min/', $step['label'], $m)) {
                $leg = $this->buildWalkLeg($prev['stop_id'], $step['stop_id'], $m[1]);
                if ($leg)
                    $legs[] = $leg;
            }
        }
        // Merge adjacent walk legs
        $merged = [];
        foreach ($legs as $leg) {
            $n = count($merged);
            if ($n && $merged[$n - 1]['mode'] === 'walk' && $leg['mode'] === 'walk') {
                // extend previous walk
                $merged[$n - 1]['minutes'] += $leg['minutes'] ?? 0;
                $merged[$n - 1]['to_stop'] = $leg['to_stop'] ?? ($merged[$n - 1]['to_stop'] ?? null);
                // optional: merge coordinates arrays if you keep them
            } else {
                $merged[] = $leg;
            }
        }
        $legs = $merged;
        return ['legs' => $legs, 'has_variations' => $hasVariations];
    }

    /**
     * RAPTOR-lite: corridor-aware (skip relaxations outside whitelist; dest always allowed)
     */
    private function raptorLiteRoute(
        array $originStops,
        array $destStops,
        bool $includeWalking = true,
        ?Carbon $departAfter = null,
        bool $isEventDay = false,
        float $dlat = 0.0,
        float $dlng = 0.0,
        float $olat = 0.0,
        float $olng = 0.0,
        float $odKm = 0.0
    ): array {
        $deadline = microtime(true) + self::DEADLINE_SEC;

        $this->isEventDay = $isEventDay;
        // ---- Soft-window pruning state ----
        $bestSolutionCost = INF;

        // adaptive window (minutes): wider for longer trips
        $softWindow = $this->softWindowMinutes($odKm ?? 0.0); // we'll add method below

        // optional: small diversity caps by transfer count (prevents all solutions being 0/1-transfer CBD hops)
        $bucketCaps = [0 => 10, 1 => 12, 2 => 8, 3 => 6, 4 => 4];  // tuneable
        $bucketCounts = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];

        // local helper to add a solution with soft window + diversity control
        $pushSolution = function (array $path, float $cost, int $transfers) use (&$solutions, &$bestSolutionCost, $softWindow, &$bucketCounts, $bucketCaps) {
            // normalize bucket index
            $b = $transfers >= 4 ? 4 : max(0, $transfers);

            // soft window: accept if within best + window (always accept a new best)
            $withinWindow = ($cost <= $bestSolutionCost + $softWindow) || !is_finite($bestSolutionCost);

            // simple diversity guard: only a limited number per transfer bucket
            $underCap = ($bucketCounts[$b] ?? 0) < ($bucketCaps[$b] ?? PHP_INT_MAX);

            if ($withinWindow && $underCap) {
                $solutions[] = $path;
                $bucketCounts[$b] = ($bucketCounts[$b] ?? 0) + 1;
                if ($cost < $bestSolutionCost)
                    $bestSolutionCost = $cost;
                return true;
            }
            return false;
        };


        $destIds = array_map(fn($s) => $s['stop_id'], $destStops);
        $destSet = array_fill_keys($destIds, true);
        $isCorridor = is_array($this->corridorAllowedStops);

        // Seed
        $best = [];
        $queueThisRound = [];
        foreach ($originStops as $s) {
            $sid = $s['stop_id'];
            if ($isCorridor && !$this->isAllowedStop($sid, isset($destSet[$sid])))
                continue;
            $best[$sid] = [
                't' => 0.0,
                'transfers' => 0,
                'path' => [['stop_id' => $sid, 'label' => 'start']],
                'last_route' => null,
                'first_board' => null,
                'elapsed' => 0.0,
            ];
            $queueThisRound[$sid] = true;
        }

        $solutions = [];
        $round = 0;

        while ($round < self::MAX_ROUNDS && microtime(true) < $deadline) {
            $round++;

            // (1) select routes to scan
            $routesToScan = [];
            foreach (array_keys($queueThisRound) as $sid) {
                if ($isCorridor && !$this->isAllowedStop($sid, isset($destSet[$sid])))
                    continue;
                foreach ($this->routesPassingThrough($sid) as $srid) {
                    $stops = $this->getRouteStops($srid);
                    $idx = array_search($sid, $stops, true);
                    if ($idx === false)
                        continue;
                    if (!isset($routesToScan[$srid]) || $idx < $routesToScan[$srid]) {
                        $routesToScan[$srid] = $idx;
                    }
                }
            }
            if (!$routesToScan)
                break;

            $queueNextRound = [];

            // (2) scan each route
            foreach ($routesToScan as $srid => $startIdx) {
                $stops = $this->getRouteStops($srid);
                if (!$stops)
                    continue;

                [$nextTable, $cumKm] = $this->precompForRouteOnTheFly($srid, $stops);

                $boardSid = $stops[$startIdx];
                $labelAtBoard = $best[$boardSid] ?? null;
                if (!$labelAtBoard)
                    continue;
                if ($isCorridor && !$this->isAllowedStop($boardSid, isset($destSet[$boardSid])))
                    continue;

                $tBoard = $labelAtBoard['t'];
                $elapsedToBoard = (float) ($labelAtBoard['elapsed'] ?? 0.0);
                $firstBoard = $labelAtBoard['first_board'] ?? ($labelAtBoard['last_route'] ? $labelAtBoard['first_board'] : $boardSid);

                $indices = $nextTable[(string) $startIdx] ?? [count($stops) - 1];
                foreach ($indices as $i) {
                    if ($i <= $startIdx)
                        continue;
                    $toSid = $stops[$i];
                    $isDest = isset($destSet[$toSid]);

                    if ($isCorridor && !$this->isAllowedStop($toSid, $isDest))
                        continue;

                    $rideMin = 0.0;
                    $km = null;
                    if ($cumKm && isset($cumKm[$i], $cumKm[$startIdx])) {
                        $km = max(0.0, (float) $cumKm[$i] - (float) $cumKm[$startIdx]);
                        $rideMin = ($km / self::BUS_SPEED_KMPH) * 60.0;
                    } else {
                        [$lat1, $lng1] = $this->getStopLL($boardSid);
                        [$lat2, $lng2] = $this->getStopLL($toSid);
                        $km = $this->haversineKm($lat1, $lng1, $lat2, $lng2);
                        $rideMin = ($km / self::BUS_SPEED_KMPH) * 60.0;
                    }
                    // NEW: skip recommending ultra-short bus legs (keep realism)
                    $minBusKm = $this->isCBDStopOnTheFly($boardSid) ? 0.85 : 0.50; // tune as desired
                    if ($km !== null && $km < $minBusKm && !$isDest) {
                        continue; // too short to board+alight a bus here
                    }

                    // NEW: Block recommendations that use less than 1/15 of a sacco route that is more than 120km long
                    if ($cumKm) {
                        $totalRouteKm = end($cumKm);
                        if ($totalRouteKm > 120.0) {
                            $minLegKm = $totalRouteKm / 15.0;
                            if ($km !== null && $km < $minLegKm && !$isDest) {
                                continue;
                            }
                        }
                    }
                    // NEW: adaptive "no big backtracking" (unless exempt)
                    [$bLat, $bLng] = $this->getStopLL($boardSid);
                    [$tLat, $tLng] = $this->getStopLL($toSid);
                    $distB = $this->haversineKm($bLat, $bLng, $dlat, $dlng);
                    $distT = $this->haversineKm($tLat, $tLng, $dlat, $dlng);
                    $delta = $distT - $distB;

                    $deltaAbsKm = ($odKm >= 80.0) ? 12.0 : 10.0;
                    $deltaFrac = ($odKm >= 80.0) ? 0.18 : 0.15;
                    $hardCap = min(60.0, 0.25 * max(1.0, $odKm));
                    $thresh = min($hardCap, max($deltaAbsKm, $deltaFrac * $odKm));

                    $exempt = $this->isDownstreamOnRoute($srid, $boardSid, $toSid) // same route forward
                        || isset($this->hubStops[$toSid])
                        || $this->isAllowedStop($toSid, $isDest);

                    if (!$exempt && $delta > $thresh && !$isDest) {
                        continue;
                    }


                    $prevRoute = $labelAtBoard['last_route'];
                    $newTransfers = $labelAtBoard['transfers'] + (($prevRoute && $prevRoute !== $srid) ? 1 : 0);
                    $transferPenalty = (($prevRoute && $prevRoute !== $srid) ? self::TRANSFER_PENALTY : 0.0);

                    $tripData = null;
                    $waitMin = 0.0;
                    $actualRideMin = $rideMin;
                    if ($departAfter) {
                        $arrivalAtBoard = $departAfter->copy()->addMinutes($elapsedToBoard);
                        $tripSelection = $this->selectTripForSegment($srid, $boardSid, $toSid, $arrivalAtBoard);
                        if ($tripSelection) {
                            $tripData = $tripSelection['trip'] ?? null;
                            $tripDeparture = $tripSelection['departure'] ?? null;
                            $tripArrival = $tripSelection['arrival'] ?? null;
                            if ($tripDeparture instanceof Carbon) {
                                $waitMin = max(0.0, (float) $arrivalAtBoard->diffInMinutes($tripDeparture));
                                if ($tripArrival instanceof Carbon) {
                                    $actualRideMin = max(0.0, (float) $tripDeparture->diffInMinutes($tripArrival));
                                }
                            }
                        }
                    }

                    $tArr = $tBoard + $rideMin + $transferPenalty;
                    $newElapsed = $elapsedToBoard + $waitMin + $actualRideMin;
                    $newPath = array_merge($labelAtBoard['path'], [
                        [
                            'stop_id' => $toSid,
                            'label' => 'bus via ' . $srid,
                            'trip' => $tripData,
                            'km' => $km,
                        ]
                    ]);

                    if ($isDest) {
                        $fullPath = array_merge($newPath, [['stop_id' => $toSid, 'label' => 'arrive']]);
                        // use the label at board and this segment’s change flag to infer transfers here
                        $transfersForThis = $newTransfers;   // already counted above for route change
                        $pushSolution($fullPath, $tArr, $transfersForThis);
                    }

                    $old = $best[$toSid] ?? null;
                    if ($old === null || $tArr < $old['t']) {
                        $best[$toSid] = [
                            't' => $tArr,
                            'transfers' => $newTransfers,
                            'path' => $newPath,
                            'last_route' => $srid,
                            'first_board' => $firstBoard ?? $boardSid,
                            'elapsed' => $newElapsed,
                        ];
                        $queueNextRound[$toSid] = true;
                    }
                }
            }

            //if (count($solutions) >= self::MAX_CANDIDATES || microtime(true) >= $deadline) break;
            // (3) walking transfers
            if ($includeWalking) {
                foreach (array_keys($queueNextRound) as $sid) {

                    $label = $best[$sid] ?? null;
                    if (!$label)
                        continue;

                    foreach ($this->walkFromOnTheFly($sid) as $e) {
                        $to = $e['to'];

                        $lastRoute = $label['last_route'] ?? null;

                        // If we were just on a route and the walk target is downstream on that same route,
// walking off and re-boarding is dominated by staying on. Skip it.
                        if ($lastRoute && $this->isDownstreamOnRoute($lastRoute, $sid, $to)) {
                            continue;
                        }
                        $walkSec = (int) ($e['sec'] ?? 0);
                        $walkMin = (int) ceil($walkSec / 60);
                        $edgeCost = $walkMin * self::WALK_WEIGHT;
                        if (!empty($label['last_route'])) {
                            $edgeCost += self::TRANSFER_PENALTY;
                        }
                        $firstBoard = $label['first_board'] ?? null;
                        $isDest = isset($destSet[$to]);

                        if ($this->corridorAllowedStops && !$this->isAllowedStop($to, $isDest))
                            continue;


                        $t2 = $label['t'] + $edgeCost;
                        $newElapsed = (float) ($label['elapsed'] ?? 0.0) + $walkMin;
                        $newPath = array_merge($label['path'], [['stop_id' => $to, 'label' => 'walk ' . $walkMin . ' min']]);

                        if ($isDest) {
                            $fullPath = array_merge($newPath, [['stop_id' => $to, 'label' => 'arrive']]);
                            // leaving a bus to walk already paid a transfer; transfers count stays the same as label['transfers']
                            $transfersForThis = (int) ($label['transfers'] ?? 0);
                            $pushSolution($fullPath, $t2, $transfersForThis);
                            continue;
                        }

                        $old = $best[$to] ?? null;
                        if ($old === null || $t2 < $old['t']) {
                            $best[$to] = [
                                't' => $t2,
                                'transfers' => $label['transfers'],
                                'path' => $newPath,
                                'last_route' => null,
                                'first_board' => $firstBoard,
                                'elapsed' => $newElapsed,
                            ];
                            if (microtime(true) >= $deadline)
                                break;
                            if (count($solutions) >= self::MAX_CANDIDATES)
                                break;
                            $queueNextRound[$to] = true;
                        }
                    }
                }
            }

            $queueThisRound = $queueNextRound;
        }
        // Do not slice here. Return all collected solutions; we’ll dedupe and top-N later.
        return $solutions;
    }

    private function isAllowedStop(string $stopId, bool $isDest): bool
    {
        // 1) Destination stop is always allowed
        if ($isDest)
            return true;

        // 2) Hubs are always allowed (regardless of corridor)
        if (isset($this->hubStops[$stopId]))
            return true;

        // 3) NEW: Any CBD stop is always allowed (soft-hub behaviour)
        if ($this->isCBDStopOnTheFly($stopId))
            return true;

        // 4) If no corridor gating is active, everything is allowed
        if ($this->corridorAllowedStops === null)
            return true;

        // 5) Otherwise: must be in the corridor whitelist
        return isset($this->corridorAllowedStops[$stopId]);
    }

    // --------- everything below here is unchanged from your latest file (helpers/caches) ---------

    private function dedupeMultiLeg(array $enrichedPaths): array
    {
        $bestByCombo = [];

        foreach ($enrichedPaths as $enriched) {
            $key = $this->comboKeyFromEnriched($enriched);
            $cost = $this->totalCostFromEnriched($enriched);
            $hubScore = $this->hubScore($enriched);

            if (!isset($bestByCombo[$key])) {
                $bestByCombo[$key] = ['path' => $enriched, 'cost' => $cost, 'hubScore' => $hubScore];
            } else {
                $cur = $bestByCombo[$key];
                if ($cost < $cur['cost'] || ($cost === $cur['cost'] && $hubScore > $cur['hubScore'])) {
                    $bestByCombo[$key] = ['path' => $enriched, 'cost' => $cost, 'hubScore' => $hubScore];
                }
            }
        }

        usort($bestByCombo, fn($a, $b) => $a['cost'] <=> $b['cost']);
        return array_values(array_map(fn($x) => $x['path'], $bestByCombo));
    }

    private function diversifyResults(array $results, array $preferredSaccos = []): array
    {
        $preferredMap = array_fill_keys($preferredSaccos, true);

        // Track usage of SaccoRoutes per leg index: [legIndex => [saccoRouteId => count]]
        $usagePerLeg = [];

        foreach ($results as $rIndex => &$result) {
            $legs = $result['legs'] ?? [];

            // Identify bus leg indices
            $busLegIndices = [];
            foreach ($legs as $i => $leg) {
                if (($leg['mode'] ?? '') === 'bus') {
                    $busLegIndices[] = $i;
                }
            }

            foreach ($busLegIndices as $legIdx) {
                $leg = $legs[$legIdx];
                $saccoRouteId = $leg['sacco_route_id'];
                $routeId = $leg['route_id'];
                $saccoId = explode('_', $saccoRouteId)[0] ?? ''; // Assuming format SACCO_ROUTE_INDEX

                // Check if this is a preferred sacco
                // We check if the sacco_route_id starts with any of the preferred sacco IDs (if they are passed as IDs)
                // Or if we can derive sacco_id from sacco_route_id. 
                // Usually sacco_route_id is like "MO0004_...". The sacco_id is "MO0004".
                // Let's try to match against the sacco_id in the leg if available, or parse it.
                // The leg has 'sacco_name', but we likely passed IDs in preferred_saccos.
                // Let's assume preferred_saccos contains sacco_ids (e.g. "MO0004").

                $isPreferred = isset($preferredMap[$saccoId]);

                // Usage count for this specific sacco_route_id at this leg index
                $count = $usagePerLeg[$legIdx][$saccoRouteId] ?? 0;

                // DECISION: Substitute if (Repeated AND Not Preferred)
                if ($count > 0 && !$isPreferred) {
                    // Try to find an alternative
                    $altResult = $this->findAlternativeSaccoRoute(
                        $routeId,
                        $leg['board_stop']['stop_id'],
                        $leg['alight_stop']['stop_id'],
                        $usagePerLeg[$legIdx] ?? [], // exclude these if possible
                        $preferredMap,
                        $saccoId // Pass current sacco ID to avoid it if possible
                    );

                    if ($altResult) {
                        $alternative = $altResult['sacco_route'];
                        $newBoardId = $altResult['board_stop_id'];
                        $newAlightId = $altResult['alight_stop_id'];

                        // Attempt to build the new leg
                        $newLeg = $this->buildBusLeg(
                            $alternative->sacco_route_id,
                            $newBoardId,
                            $newAlightId,
                            null, // trip (will be re-fetched if needed)
                            null, // Recalculate distance
                            null, // departAfter
                            $this->isEventDay
                        );

                        if ($newLeg) {
                            // STITCHING: Check if we need walks
                            $preWalk = null;
                            $postWalk = null;
                            $validStitch = true;

                            // 1. Pre-Walk (Old Board -> New Board)
                            if ($newBoardId !== $leg['board_stop']['stop_id']) {
                                // We need to walk from where we were supposed to be (Old Board) to where we need to be (New Board)
                                // Wait, if this is the FIRST leg, we just change the start point?
                                // Or do we assume the user is at the "Old Board" location?
                                // Usually, the previous leg ended at "Old Board". So we walk Old Board -> New Board.
                                $preWalk = $this->buildWalkLeg($leg['board_stop']['stop_id'], $newBoardId, 5); // 5 min default, will calculate
                                if (!$preWalk) {
                                    $validStitch = false;
                                }
                            }

                            // 2. Post-Walk (New Alight -> Old Alight)
                            if ($validStitch && $newAlightId !== $leg['alight_stop']['stop_id']) {
                                // We need to walk from where we got off (New Alight) to where the next leg starts (Old Alight)
                                $postWalk = $this->buildWalkLeg($newAlightId, $leg['alight_stop']['stop_id'], 5);
                                if (!$postWalk) {
                                    $validStitch = false;
                                }
                            }

                            if ($validStitch) {
                                // Construct new sequence for this leg slot
                                $replacement = [];
                                if ($preWalk)
                                    $replacement[] = $preWalk;
                                $replacement[] = $newLeg;
                                if ($postWalk)
                                    $replacement[] = $postWalk;

                                // Splice into legs array
                                // Note: array_splice re-indexes, which might mess up our loop if we iterate by index.
                                // But we collected $busLegIndices beforehand.
                                // If we modify $legs, the indices of SUBSEQUENT legs shift.
                                // This is tricky.
                                // EASIER: Just replace the current element with the list, and flatten later?
                                // Or use a nested structure and flatten at the end?
                                // Let's use a temporary key to store the replacement, then rebuild.

                                $legs[$legIdx] = $replacement; // We will flatten this later

                                $saccoRouteId = $alternative->sacco_route_id;
                                // Update isPreferred status for the new leg
                                $newSaccoId = explode('_', $saccoRouteId)[0];
                                $isPreferred = isset($preferredMap[$newSaccoId]);
                            }
                        }
                    }
                }

                // Record usage
                if (!isset($usagePerLeg[$legIdx])) {
                    $usagePerLeg[$legIdx] = [];
                }
                if (!isset($usagePerLeg[$legIdx][$saccoRouteId])) {
                    $usagePerLeg[$legIdx][$saccoRouteId] = 0;
                }
                $usagePerLeg[$legIdx][$saccoRouteId]++;
            }

            // Flatten legs (handle replacements)
            $flatLegs = [];
            foreach ($legs as $l) {
                if (isset($l[0]) && is_array($l[0])) {
                    // It's a list of legs (replacement)
                    foreach ($l as $sub)
                        $flatLegs[] = $sub;
                } else {
                    $flatLegs[] = $l;
                }
            }
            $result['legs'] = $flatLegs;

            // Re-summarize (update total duration/cost)
            $result = $this->updateResultSummary($result);
        }

        return $results;
    }

    private function findAlternativeSaccoRoute(
        string $routeId,
        string $boardStopId,
        string $alightStopId,
        array $excludeSaccoRoutes, // [sacco_route_id => count]
        array $preferredMap,
        ?string $avoidSaccoId = null
    ): ?array {
        // Fetch all candidates for this route
        $candidates = SaccoRoutes::where('route_id', $routeId)->get();

        $validCandidates = [];

        // Get coordinates of original stops for proximity check
        [$bLat, $bLng] = $this->getStopLL($boardStopId);
        [$aLat, $aLng] = $this->getStopLL($alightStopId);

        foreach ($candidates as $cand) {
            $id = $cand->sacco_route_id;
            $stops = $cand->stop_ids ?? [];

            // 1. Exact Match
            $bIdx = array_search($boardStopId, $stops);
            $aIdx = array_search($alightStopId, $stops);

            if ($bIdx !== false && $aIdx !== false && $bIdx < $aIdx) {
                $validCandidates[] = [
                    'sacco_route' => $cand,
                    'board_stop_id' => $boardStopId,
                    'alight_stop_id' => $alightStopId,
                    'score' => 0 // 0 = perfect
                ];
                continue;
            }

            // 2. Proximity Match (Stitching)
            // Find nearest stop on this route to boardStop
            $bestB = null;
            $minBDist = INF;
            $bestBIdx = -1;
            $bestA = null;
            $minADist = INF;
            $bestAIdx = -1;

            foreach ($stops as $idx => $sid) {
                [$sLat, $sLng] = $this->getStopLL($sid);
                if ($sLat === null)
                    continue;

                $d = $this->haversineKm($bLat, $bLng, $sLat, $sLng);
                if ($d < $minBDist) {
                    $minBDist = $d;
                    $bestB = $sid;
                    $bestBIdx = $idx;
                }

                $d2 = $this->haversineKm($aLat, $aLng, $sLat, $sLng);
                if ($d2 < $minADist) {
                    $minADist = $d2;
                    $bestA = $sid;
                    $bestAIdx = $idx;
                }
            }

            // Threshold: 10km (10.0) - Let re-ranking handle the penalty
            if ($minBDist < 10.0 && $minADist < 10.0 && $bestBIdx < $bestAIdx) {
                $validCandidates[] = [
                    'sacco_route' => $cand,
                    'board_stop_id' => $bestB,
                    'alight_stop_id' => $bestA,
                    'score' => $minBDist + $minADist // Penalty for walking
                ];
            } else {
                \Log::debug("Candidate $id rejected. BoardDist: $minBDist, AlightDist: $minADist");
            }
        }

        if (empty($validCandidates)) {
            \Log::info("No valid candidates found for route $routeId");
            return null;
        }

        // Sort candidates
        usort($validCandidates, function ($a, $b) use ($preferredMap, $excludeSaccoRoutes, $avoidSaccoId) {
            $candA = $a['sacco_route'];
            $candB = $b['sacco_route'];
            $idA = $candA->sacco_route_id;
            $idB = $candB->sacco_route_id;
            $saccoA = explode('_', $idA)[0];
            $saccoB = explode('_', $idB)[0];

            $prefA = isset($preferredMap[$saccoA]);
            $prefB = isset($preferredMap[$saccoB]);

            if ($prefA && !$prefB)
                return -1;
            if (!$prefA && $prefB)
                return 1;

            // Prioritize DIFFERENT sacco
            $isAvoidA = ($saccoA === $avoidSaccoId);
            $isAvoidB = ($saccoB === $avoidSaccoId);
            if (!$isAvoidA && $isAvoidB)
                return -1;
            if ($isAvoidA && !$isAvoidB)
                return 1;

            // Prioritize Exact Match (lower score)
            if (abs($a['score'] - $b['score']) > 0.01) {
                return $a['score'] <=> $b['score'];
            }

            $countA = $excludeSaccoRoutes[$idA] ?? 0;
            $countB = $excludeSaccoRoutes[$idB] ?? 0;

            return $countA <=> $countB;
        });

        \Log::info("Sorted candidates: " . implode(', ', array_map(fn($c) => $c['sacco_route']->sacco_route_id, $validCandidates)));

        return $validCandidates[0];
    }

    private function updateResultSummary(array $result): array
    {
        $legs = $result['legs'] ?? [];
        $total = 0.0;
        foreach ($legs as $leg) {
            $mode = $leg['mode'] ?? null;
            if ($mode === 'bus') {
                $total += (float) ($leg['duration_minutes'] ?? 0);
            } elseif ($mode === 'walk') {
                $total += (float) ($leg['minutes'] ?? 0);
            }
        }
        if (!isset($result['summary'])) {
            $result['summary'] = [];
        }
        $result['summary']['total_duration_minutes'] = round($total, 1);
        return $result;
    }

    private function comboKeyFromEnriched(array $enriched): string
    {
        $seq = [];
        foreach ($enriched['legs'] as $leg) {
            if (($leg['mode'] ?? '') === 'bus') {
                $seq[] = 'R:' . $leg['sacco_route_id'];
            }
        }
        return implode('>', $seq);
    }

    private function totalCostFromEnriched(array $enriched): float
    {
        $rideMin = 0.0;
        $walkMin = 0.0;
        $transfers = 0;
        $prevBusRoute = null;
        foreach ($enriched['legs'] as $leg) {
            if (($leg['mode'] ?? '') === 'bus') {
                $rideMin += $this->estimateBusMinutesForLeg($leg);
                $route = $leg['sacco_route_id'] ?? null;
                if ($route !== null && $prevBusRoute !== null && $route !== $prevBusRoute) {
                    $transfers++;
                }
                $prevBusRoute = $route;
            } elseif (($leg['mode'] ?? '') === 'walk') {
                $walkMin += (float) ($leg['minutes'] ?? 0);
                // NOTE: do NOT reset $prevBusRoute here; we want to count changes across walks
            }
        }
        return $rideMin + self::WALK_WEIGHT * $walkMin + self::TRANSFER_PENALTY * $transfers;
    }

    private function hubScore(array $enriched): int
    {
        $score = 0;
        foreach ($enriched['legs'] as $leg) {
            if (($leg['mode'] ?? '') !== 'bus')
                continue;
            $b = $leg['board_stop']['stop_id'] ?? null;
            $a = $leg['alight_stop']['stop_id'] ?? null;
            if ($b && isset($this->hubStops[$b]))
                $score++;
            if ($a && isset($this->hubStops[$a]))
                $score++;
        }
        return $score;
    }

    private function estimateBusMinutesForLeg(array $leg): float
    {
        $coords = $leg['coordinates'] ?? [];
        if (is_array($coords) && count($coords) >= 2) {
            $km = 0.0;
            for ($i = 1; $i < count($coords); $i++) {
                [$lat1, $lng1] = $coords[$i - 1];
                [$lat2, $lng2] = $coords[$i];
                $km += $this->haversineKm($lat1, $lng1, $lat2, $lng2);
            }
            return ($km / self::BUS_SPEED_KMPH) * 60.0;
        }

        $b = $leg['board_stop'] ?? null;
        $a = $leg['alight_stop'] ?? null;
        if ($b && $a) {
            $km = $this->haversineKm((float) $b['lat'], (float) $b['lng'], (float) $a['lat'], (float) $a['lng']);
            return ($km / self::BUS_SPEED_KMPH) * 60.0;
        }
        return 0.0;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1); // <- remove stray $dlng assignment
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $R * asin(min(1.0, sqrt($a)));
    }

    private function routeDistanceKm(array $stopIds): ?float
    {
        if (count($stopIds) < 2) {
            return null;
        }

        $firstStop = $this->stopInfo((string) $stopIds[0]);
        $lastStop = $this->stopInfo((string) $stopIds[count($stopIds) - 1]);

        if (!$firstStop || !$lastStop) {
            return null;
        }

        return $this->fareCalculator->distanceBetween(
            (float) $firstStop->stop_lat,
            (float) $firstStop->stop_long,
            (float) $lastStop->stop_lat,
            (float) $lastStop->stop_long
        );
    }

    private function getRouteStops(string $saccoRouteId): array
    {
        if (!isset($this->routeStops[$saccoRouteId])) {
            if (!isset($this->routeCache[$saccoRouteId])) {
                $this->routeCache[$saccoRouteId] = SaccoRoutes::query()
                    ->select(['sacco_route_id', 'stop_ids'])
                    ->where('sacco_route_id', $saccoRouteId)
                    ->first();
            }
            $stops = $this->routeCache[$saccoRouteId]?->stop_ids ?? [];
            $this->routeStops[$saccoRouteId] = is_array($stops) ? $stops : [];
        }
        return $this->routeStops[$saccoRouteId];
    }

    private function getRouteTrips(string $saccoRouteId)
    {
        if (!isset($this->routeTrips[$saccoRouteId])) {
            $this->routeTrips[$saccoRouteId] = Trip::query()
                ->where('sacco_route_id', $saccoRouteId)
                ->orderBy('start_time')
                ->orderBy('trip_index')
                ->get();
        }

        return $this->routeTrips[$saccoRouteId];
    }

    private function selectTripForSegment(string $saccoRouteId, string $boardStopId, string $alightStopId, Carbon $earliestDeparture): ?array
    {
        $trips = $this->getRouteTrips($saccoRouteId);
        if (!$trips || $trips->isEmpty()) {
            return null;
        }

        $boardStopId = (string) $boardStopId;
        $alightStopId = (string) $alightStopId;

        foreach ($trips as $trip) {
            $stopTimes = is_array($trip->stop_times) ? $trip->stop_times : [];
            if (!$stopTimes) {
                continue;
            }

            $boardIndex = null;
            $alightIndex = null;
            foreach ($stopTimes as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sid = isset($row['stop_id']) ? (string) $row['stop_id'] : null;
                if ($sid === $boardStopId && $boardIndex === null) {
                    $boardIndex = $idx;
                }
                if ($sid === $alightStopId && $boardIndex !== null) {
                    $alightIndex = $idx;
                    break;
                }
            }

            if ($boardIndex === null || $alightIndex === null) {
                continue;
            }

            $boardEntry = $stopTimes[$boardIndex] ?? null;
            $boardTimeStr = is_array($boardEntry) ? ($boardEntry['departure_time'] ?? $boardEntry['time'] ?? null) : null;
            if (!is_string($boardTimeStr) || trim($boardTimeStr) === '') {
                continue;
            }

            $boardDeparture = $this->parseTripTime($earliestDeparture, $boardTimeStr);
            if (!$boardDeparture || $boardDeparture->lt($earliestDeparture)) {
                continue;
            }

            $alightEntry = $stopTimes[$alightIndex] ?? null;
            $alightTimeStr = is_array($alightEntry) ? ($alightEntry['arrival_time'] ?? $alightEntry['time'] ?? null) : null;
            $arrival = null;
            if (is_string($alightTimeStr) && trim($alightTimeStr) !== '') {
                $arrival = $this->parseTripTime($boardDeparture, $alightTimeStr);
                if ($arrival && $arrival->lt($boardDeparture)) {
                    $arrival->addDay();
                }
            }

            $segmentStopTimes = array_values(array_slice($stopTimes, $boardIndex, $alightIndex - $boardIndex + 1));

            return [
                'trip' => [
                    'trip_id' => $trip->getAttribute('trip_id'),
                    'trip_index' => $trip->trip_index,
                    'start_time' => $trip->start_time ?? $boardDeparture->format('H:i:s'),
                    'departure_time' => $boardDeparture->format('H:i:s'),
                    'arrival_time' => $arrival ? $arrival->format('H:i:s') : null,
                    'stop_times' => $segmentStopTimes,
                ],
                'departure' => $boardDeparture,
                'arrival' => $arrival,
            ];
        }

        return null;
    }

    private function parseTripTime(Carbon $base, string $time): ?Carbon
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($time, $base->getTimezone());
        } catch (\Throwable $e) {
            return null;
        }

        return $base->copy()->setTime(
            (int) $parsed->format('H'),
            (int) $parsed->format('i'),
            (int) $parsed->format('s')
        );
    }

    private function getStopLL(string $stopId): array
    {
        if (!array_key_exists($stopId, $this->stopLL)) {
            $s = Stops::where('stop_id', $stopId)->first();
            $this->stopLL[$stopId] = $s ? [(float) $s->stop_lat, (float) $s->stop_long] : [null, null];
        }
        return $this->stopLL[$stopId];
    }

    /**
     * Precompute downstream scan indices (nextTable) and cumulative km (cumKm) for a route.
     * - nextTable: from each stop index -> selected downstream indices (<= JUMP_K, always includes last).
     * - cumKm: cumulative crow-flies distance along consecutive stops.
     */
    private function precompForRouteOnTheFly(string $saccoRouteId, array $stops): array
    {
        if (isset($this->nextTransfersCache[$saccoRouteId]) && isset($this->cumKmCache[$saccoRouteId])) {
            return [$this->nextTransfersCache[$saccoRouteId], $this->cumKmCache[$saccoRouteId]];
        }

        $n = count($stops);
        $cumKm = [];
        if ($n > 0) {
            $cumKm[0] = 0.0;
            for ($i = 1; $i < $n; $i++) {
                [$lat1, $lng1] = $this->getStopLL($stops[$i - 1]);
                [$lat2, $lng2] = $this->getStopLL($stops[$i]);
                $seg = 0.0;
                if ($lat1 !== null && $lng1 !== null && $lat2 !== null && $lng2 !== null) {
                    $seg = $this->haversineKm($lat1, $lng1, $lat2, $lng2);
                }
                $cumKm[$i] = ($cumKm[$i - 1] ?? 0.0) + max(0.0, $seg);
            }
        }

        $nextTable = [];
        for ($s = 0; $s < max(0, $n - 1); $s++) {
            $list = [];
            // Dense window when the boarding stop is in the CBD
            $isCBDStart = $this->isCBDStopOnTheFly($stops[$s]);
            if ($isCBDStart) {
                $limit = min($n - 1, $s + 15);
                for ($i = $s + 1; $i <= $limit; $i++) {
                    $list[] = $i;
                }
            }

            // Subsample the rest after the dense window
            $remaining = ($n - 1) - (empty($list) ? $s : max($list));
            $from = empty($list) ? ($s + 1) : (max($list) + 1);
            if ($remaining > 0) {
                $step = max(1, (int) ceil($remaining / self::JUMP_K));
                for ($i = $from; $i < $n; $i += $step) {
                    $list[] = $i;
                }
            }


            // ensure last index is present
            if ($n > 0 && (empty($list) || end($list) !== $n - 1)) {
                $list[] = $n - 1;
            }

            // unique & sorted just in case
            $list = array_values(array_unique($list));
            sort($list);
            $nextTable[(string) $s] = $list;
        }

        $this->cumKmCache[$saccoRouteId] = $cumKm;
        $this->nextTransfersCache[$saccoRouteId] = $nextTable;

        return [$nextTable, $cumKm];
    }

    private function walkFromOnTheFly(string $fromStopId): array
    {
        if (!isset($this->walkFromCache[$fromStopId])) {
            $edges = \App\Models\TransferEdge::where('from_stop_id', $fromStopId)->get();

            $mapped = $edges->map(function ($e) {
                $seconds = (int) $e->walk_time_seconds;
                $coords = is_array($e->geometry) ? $e->geometry : [];
                $distance = $this->edgeDistanceMOnTheFly($e);

                if (empty($coords) || $distance <= 0) {
                    $distance = $this->estimateWalkDistanceFromSeconds($seconds);
                }

                return [
                    'to' => $e->to_stop_id,
                    'sec' => $seconds,
                    'dist' => $distance,
                    'coords' => $coords,
                ];
            });

            $filtered = $mapped->filter(function (array $edge) {
                $distance = $edge['dist'];

                if ($distance <= 0 && ($edge['sec'] ?? 0) > 0) {
                    $distance = $this->estimateWalkDistanceFromSeconds((int) $edge['sec']);
                }

                return $distance <= self::WALK_CAP_M;
            })->values();

            $this->walkFromCache[$fromStopId] = $filtered->toArray();
        }
        return $this->walkFromCache[$fromStopId];
    }

    private function edgeDistanceMOnTheFly($edge): int
    {
        $coords = $edge->geometry ?? [];
        if (is_array($coords) && count($coords) >= 2) {
            $m = 0.0;
            for ($i = 1; $i < count($coords); $i++) {
                $m += $this->haversineKm($coords[$i - 1][0], $coords[$i - 1][1], $coords[$i][0], $coords[$i][1]) * 1000.0;
            }
            if ($m > 1)
                return (int) round($m);
        }
        $sec = (int) ($edge->walk_time_seconds ?? 0);
        return $this->estimateWalkDistanceFromSeconds($sec);
    }

    private function estimateWalkDistanceFromSeconds(int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }

        $speedMps = (self::WALK_SPEED_KMPH * 1000.0) / 3600.0;

        return (int) round($seconds * $speedMps);
    }

    private function isCBDStopOnTheFly(string $sid): bool
    {
        if (isset($this->isCbdStopCache[$sid]))
            return $this->isCbdStopCache[$sid];
        $s = $this->stopInfo($sid);
        if (!$s)
            return $this->isCbdStopCache[$sid] = false;
        $inside = $this->pointInPoly((float) $s->stop_lat, (float) $s->stop_long, $this->CBD_POLY);
        return $this->isCbdStopCache[$sid] = $inside;
    }

    private function pointInPoly(float $lat, float $lng, array $poly): bool
    {
        // Standard horizontal-ray casting: y=lat, x=lng
        $inside = false;
        for ($i = 0, $j = count($poly) - 1; $i < count($poly); $j = $i++) {
            [$yi, $xi] = $poly[$i];    // [lat, lng]
            [$yj, $xj] = $poly[$j];
            $crosses = (($yi > $lat) != ($yj > $lat)) &&
                ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-9) + $xi);
            if ($crosses)
                $inside = !$inside;
        }
        return $inside;
    }
    private function allCBDStopIds(): array
    {
        static $cache = null;
        if ($cache !== null)
            return $cache;

        $lats = array_column($this->CBD_POLY, 0);
        $lngs = array_column($this->CBD_POLY, 1);
        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);

        $stops = Stops::whereBetween('stop_lat', [$minLat, $maxLat])
            ->whereBetween('stop_long', [$minLng, $maxLng])
            ->get();

        $ids = [];
        foreach ($stops as $s) {
            if ($this->pointInPoly((float) $s->stop_lat, (float) $s->stop_long, $this->CBD_POLY)) {
                $ids[] = (string) $s->stop_id;
            }
        }
        return $cache = $ids;
    }

    private function networkDistanceMOnTheFly(string $fromStopId, string $toStopId): int
    {
        $edge = \App\Models\TransferEdge::where('from_stop_id', $fromStopId)
            ->where('to_stop_id', $toStopId)->first();
        if ($edge)
            return $this->edgeDistanceMOnTheFly($edge);

        [$lat1, $lng1] = $this->getStopLL($fromStopId);
        [$lat2, $lng2] = $this->getStopLL($toStopId);
        return (int) round($this->haversineKm($lat1, $lng1, $lat2, $lng2) * 1000.0);
    }

    private function routesPassingThrough(string $stopId): array
    {
        if (!isset($this->routesByStop[$stopId])) {
            $ids = Directions::where('direction_id', $stopId)->value('direction_routes') ?? [];
            if (is_string($ids)) {
                $decoded = json_decode($ids, true);
                $ids = is_array($decoded) ? $decoded : [];
            }
            $this->routesByStop[$stopId] = is_array($ids) ? $ids : [];
        }
        return $this->routesByStop[$stopId];
    }
    private function measureWalkDistanceM(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        ?array $coords = null
    ): int {
        if (is_array($coords) && count($coords) >= 2) {
            $km = 0.0;
            $prevLat = null;
            $prevLng = null;

            foreach ($coords as $point) {
                if (!is_array($point)) {
                    continue;
                }

                if (array_key_exists(0, $point) && array_key_exists(1, $point)) {
                    $lat = (float) $point[0];
                    $lng = (float) $point[1];
                } elseif (isset($point['lat'], $point['lng'])) {
                    $lat = (float) $point['lat'];
                    $lng = (float) $point['lng'];
                } else {
                    continue;
                }

                if ($prevLat !== null && $prevLng !== null) {
                    $km += $this->haversineKm($prevLat, $prevLng, $lat, $lng);
                }

                $prevLat = $lat;
                $prevLng = $lng;
            }

            if ($km > 0.0) {
                return (int) round($km * 1000.0);
            }
        }

        return (int) round($this->haversineKm($fromLat, $fromLng, $toLat, $toLng) * 1000.0);
    }

    private function buildAccessWalk(
        array $firstLeg,
        float $olat,
        float $olng,
        bool &$capped = false
    ): ?array {
        $capped = false;
        $firstStopId = $firstLeg['mode'] === 'bus'
            ? ($firstLeg['board_stop']['stop_id'] ?? null)
            : ($firstLeg['from_stop']['stop_id'] ?? null);
        if (!$firstStopId) {
            return null;
        }
        [$sLat, $sLng] = $this->getStopLL($firstStopId);

        $coords = [];
        $mins = null;

        if ($this->walkRouter) {
            $r = $this->walkRouter->route($olat, $olng, $sLat, $sLng);
            if ($r) {
                $coords = $r['coords'];
                if (isset($r['duration_s'])) {
                    $mins = (int) ceil($r['duration_s'] / 60);
                }
            }
        }

        $distanceM = $this->measureWalkDistanceM($olat, $olng, $sLat, $sLng, $coords ?: null);
        if ($distanceM > self::ACCESS_EGRESS_CAP_M) {
            $capped = true;
            return null;
        }

        if ($mins === null) {
            $mins = (int) ceil((($distanceM / 1000.0) / self::WALK_SPEED_KMPH) * 60.0);
        }

        return [
            'mode' => 'walk',
            'minutes' => $mins,
            'from_point' => ['label' => 'Origin', 'lat' => $olat, 'lng' => $olng],
            'to_stop' => [
                'stop_id' => $firstStopId,
                'stop_name' => $firstLeg['mode'] === 'bus'
                    ? $firstLeg['board_stop']['stop_name']
                    : $firstLeg['from_stop']['stop_name'],
                'lat' => $sLat,
                'lng' => $sLng,
            ],
            'coordinates' => $coords,
        ];
    }

    private function buildEgressWalk(
        array $lastLeg,
        float $dlat,
        float $dlng,
        bool &$capped = false
    ): ?array {
        $capped = false;
        $lastStopId = $lastLeg['mode'] === 'bus'
            ? ($lastLeg['alight_stop']['stop_id'] ?? null)
            : ($lastLeg['to_stop']['stop_id'] ?? null);
        if (!$lastStopId) {
            return null;
        }
        [$sLat, $sLng] = $this->getStopLL($lastStopId);

        $coords = [];
        $mins = null;

        if ($this->walkRouter) {
            $r = $this->walkRouter->route($sLat, $sLng, $dlat, $dlng);
            if ($r) {
                $coords = $r['coords'];
                if (isset($r['duration_s'])) {
                    $mins = (int) ceil($r['duration_s'] / 60);
                }
            }
        }

        $distanceM = $this->measureWalkDistanceM($sLat, $sLng, $dlat, $dlng, $coords ?: null);
        if ($distanceM > self::ACCESS_EGRESS_CAP_M) {
            $capped = true;
            return null;
        }

        if ($mins === null) {
            $mins = (int) ceil((($distanceM / 1000.0) / self::WALK_SPEED_KMPH) * 60.0);
        }

        return [
            'mode' => 'walk',
            'minutes' => $mins,
            'from_stop' => [
                'stop_id' => $lastStopId,
                'stop_name' => $lastLeg['mode'] === 'bus'
                    ? $lastLeg['alight_stop']['stop_name']
                    : $lastLeg['to_stop']['stop_name'],
                'lat' => $sLat,
                'lng' => $sLng,
            ],
            'to_point' => ['label' => 'Destination', 'lat' => $dlat, 'lng' => $dlng],
            'coordinates' => $coords,
        ];
    }
    /**
     * Merge nearest stops with regional hubs near a point.
     * - $baseCount/$maxK feed into your existing nearestStops()
     * - $hubCap = max hubs to add
     * - $totalCap = final cap (nearest + hubs)
     */
    private function seedStopsWithHubs(
        float $lat,
        float $lng,
        int $baseCount = 3,
        int $maxK = 6,
        int $hubCap = 2,
        int $totalCap = 5
    ) {
        $near = $this->nearestStops($lat, $lng, $baseCount, $maxK); // your existing method
        $hubs = $this->lookupTopHubsForPoint($lat, $lng, $hubCap);
        // merge, unique on stop_id, cap
        $merged = collect($near)->merge($hubs)
            ->unique('stop_id')
            ->take($totalCap)
            ->values();
        \Log::info('seedStopsWithHubs()', ['lat' => $lat, 'lng' => $lng, 'near' => $near->count(), 'hubs' => count($hubs), 'out' => $merged->count()]);
        return $merged;
    }

    /**
     * Find regions containing point (by H3 cells or polygon), then return top-N hubs
     * for those regions (ordered by rank). Adds coordinates for parity with nearestStops().
     */
    private function lookupTopHubsForPoint(float $lat, float $lng, int $limit = 2): array
    {
        // 1) find regions that contain this point
        $regions = \DB::table('transit_hub_regions')->get();
        $hit = [];
        foreach ($regions as $r) {
            $ok = false;
            // H3 membership
            if ($r->h3_cells) {
                $cells = json_decode($r->h3_cells, true) ?: [];
                $res = (int) ($r->h3_res ?? 7);
                $cell = \App\Services\H3Wrapper::latLngToCell($lat, $lng, $res);
                if (in_array((string) $cell, array_map('strval', $cells), true))
                    $ok = true;
            }
            // polygon fallback
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

        // 2) fetch hubs (top by rank) for those regions
        $rows = \DB::table('transit_hubs')
            ->whereIn('region_id', $hit)
            ->orderBy('rank')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty())
            return [];

        // 3) map to the same structure as nearestStops()
        $stops = \App\Models\Stops::whereIn('stop_id', $rows->pluck('stop_id'))->get()->keyBy('stop_id');
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
    private function isDownstreamOnRoute(string $saccoRouteId, string $fromStopId, string $toStopId): bool
    {
        $stops = $this->getRouteStops($saccoRouteId);
        $i = array_search($fromStopId, $stops, true);
        $j = array_search($toStopId, $stops, true);
        return $i !== false && $j !== false && $j > $i;
    }
    private function softWindowMinutes(float $odKm): float
    {
        // Base 15 min, widen slightly for long intercity (but not too much)
        $base = 15.0;
        $scale = 0.10 * $odKm;          // +6 min per 60 km
        $win = $base + $scale;
        // clamp to reasonable bounds
        return max(10.0, min(35.0, $win));
    }
    private function collapseCbdHops(array $enriched, int $maxWalkM = 750): array
    {
        $legs = $enriched['legs'] ?? [];
        if (count($legs) < 2)
            return $enriched;

        $out = [];
        for ($i = 0; $i < count($legs); $i++) {
            $leg = $legs[$i];
            if (($leg['mode'] ?? '') === 'bus') {
                $bCBD = $this->isCBDStopOnTheFly($leg['board_stop']['stop_id'] ?? '');
                $aCBD = $this->isCBDStopOnTheFly($leg['alight_stop']['stop_id'] ?? '');

                if ($bCBD && $aCBD) {
                    // If the leg distance can be walked under maxWalkM, replace with a walk
                    $m = $this->networkDistanceMOnTheFly(
                        (string) $leg['board_stop']['stop_id'],
                        (string) $leg['alight_stop']['stop_id']
                    );
                    if ($m > 0 && $m <= $maxWalkM) {
                        // Build a walk leg using existing builder to get coordinates if possible
                        $walkMin = (int) ceil((($m / 1000.0) / self::WALK_SPEED_KMPH) * 60.0);
                        $w = $this->buildWalkLeg(
                            (string) $leg['board_stop']['stop_id'],
                            (string) $leg['alight_stop']['stop_id'],
                            $walkMin
                        );
                        if ($w) {
                            $out[] = $w;
                            continue; // skip the bus leg
                        }
                    }
                }
            }
            $out[] = $leg;
        }

        // Merge adjacent walks again
        $merged = [];
        foreach ($out as $L) {
            $n = count($merged);
            if ($n && $merged[$n - 1]['mode'] === 'walk' && $L['mode'] === 'walk') {
                $merged[$n - 1]['minutes'] += (int) ($L['minutes'] ?? 0);
                $merged[$n - 1]['to_stop'] = $L['to_stop'] ?? ($merged[$n - 1]['to_stop'] ?? null);
            } else {
                $merged[] = $L;
            }
        }
        $enriched['legs'] = $merged;
        return $enriched;
    }
    private function corridorPortalsAlongPlan(array $plan): array
    {
        // from allowed L1/L0 cells → get top-K portals for those cells
        $cells = array_map('strval', array_merge($plan['L1'] ?? [], $plan['L0'] ?? []));
        if (!$cells)
            return [];
        $rows = \DB::table('corr_cell_portals')
            ->whereIn('cell_id', $cells)->orderBy('rank')->limit(80)->pluck('station_id')->all();
        return array_values(array_unique(array_map('strval', $rows)));
    }
    private function stationMembers(string $stationId): array
    {
        return \DB::table('corr_station_members')->where('station_id', $stationId)->pluck('stop_id')->all();
    }
    private function topCbdStopsByDegree(int $limit = 12): array
    {
        $ids = $this->allCBDStopIds();
        if (!$ids)
            return [];

        // degree = how many routes pass; reuse routesPassingThrough()
        $scored = [];
        foreach ($ids as $sid) {
            $deg = count($this->routesPassingThrough($sid));
            $scored[] = [$sid, $deg];
        }
        usort($scored, fn($a, $b) => $b[1] <=> $a[1]);
        return array_map(fn($x) => (string) $x[0], array_slice($scored, 0, $limit));
    }
    private function isSameL1Cell(string $a, string $b): bool
    {
        [$la, $lga] = $this->getStopLL($a);
        [$lb, $lgb] = $this->getStopLL($b);
        if ($la === null || $lb === null)
            return false;
        $ca = \App\Services\H3Wrapper::latLngToCell($la, $lga, 7);
        $cb = \App\Services\H3Wrapper::latLngToCell($lb, $lgb, 7);
        return (string) $ca === (string) $cb;
    }
    private function collapseUrbanRun(array $e, int $maxWalkM = 1100): array
    {
        $legs = $e['legs'] ?? [];
        if (count($legs) < 2)
            return $e;

        $out = [];
        $i = 0;
        while ($i < count($legs)) {
            // attempt to grow a run starting at i
            $j = $i;
            $inUrban = false;
            $startLat = $startLng = $endLat = $endLng = null;
            $startStop = $endStop = null;

            $grabAnchor = function ($L, &$lat, &$lng, &$sid, $asStart) {
                if (($L['mode'] ?? '') === 'bus') {
                    $node = $asStart ? ($L['board_stop'] ?? null) : ($L['alight_stop'] ?? null);
                } else {
                    $node = $asStart ? ($L['from_stop'] ?? null) : ($L['to_stop'] ?? null);
                }
                if ($node) {
                    $sid = (string) ($node['stop_id'] ?? '');
                    $lat = (float) ($node['lat'] ?? 0);
                    $lng = (float) ($node['lng'] ?? 0);
                }
            };

            $grabAnchor($legs[$i], $startLat, $startLng, $startStop, true);

            // grow while legs are short/urban
            while ($j < count($legs)) {
                $L = $legs[$j];
                $mode = $L['mode'] ?? '';
                if ($mode === 'bus') {
                    $b = $L['board_stop']['stop_id'] ?? '';
                    $a = $L['alight_stop']['stop_id'] ?? '';
                    $bCBD = $this->isCBDStopOnTheFly($b);
                    $aCBD = $this->isCBDStopOnTheFly($a);
                    $inUrban = ($bCBD || $aCBD) || $this->isSameL1Cell($b, $a);
                    $short = (float) ($L['distance_km'] ?? 0.0) <= 2.0; // tune
                    if (!($inUrban && $short))
                        break;
                } elseif ($mode === 'walk') {
                    $inUrban = true; // walks are ok inside run
                } else {
                    break;
                }
                $grabAnchor($L, $endLat, $endLng, $endStop, false);
                $j++;
            }

            // If run length >= 2 legs and walk bridge is feasible, replace
            if ($j - $i >= 2 && $startStop && $endStop && $startLat !== null && $endLat !== null) {
                $m = $this->networkDistanceMOnTheFly($startStop, $endStop);
                if ($m <= 0 || $m > $maxWalkM) {
                    // try OSRM; buildWalkLeg persists when it has data
                    if ($this->walkRouter) {
                        $r = $this->walkRouter->route($startLat, $startLng, $endLat, $endLng);
                        if ($r && ($r['coords'] ?? null)) {
                            $m = $this->measureWalkDistanceM($startLat, $startLng, $endLat, $endLng, $r['coords']);
                        }
                    }
                }
                if ($m > 0 && $m <= $maxWalkM) {
                    $mins = (int) ceil((($m / 1000.0) / self::WALK_SPEED_KMPH) * 60.0);
                    $w = $this->buildWalkLeg($startStop, $endStop, $mins) ?: [
                        'mode' => 'walk',
                        'minutes' => $mins,
                        'from_stop' => ['stop_id' => $startStop, 'lat' => $startLat, 'lng' => $startLng],
                        'to_stop' => ['stop_id' => $endStop, 'lat' => $endLat, 'lng' => $endLng],
                        'coordinates' => [],
                    ];
                    $out[] = $w;
                    $i = $j;
                    continue;
                }
            }

            // no collapse → keep original leg and advance by 1
            $out[] = $legs[$i];
            $i++;
        }

        // re-merge adjacent walks
        $merged = [];
        foreach ($out as $L) {
            $n = count($merged);
            if ($n && $merged[$n - 1]['mode'] === 'walk' && $L['mode'] === 'walk') {
                $merged[$n - 1]['minutes'] += (int) ($L['minutes'] ?? 0);
                $merged[$n - 1]['to_stop'] = $L['to_stop'] ?? ($merged[$n - 1]['to_stop'] ?? null);
            } else
                $merged[] = $L;
        }
        $e['legs'] = $merged;
        return $e;
    }
    private function loadHubStopsForTrip(float $odKm): array
    {
        $hubIds = [];

        // ---- 1) CBD hubs (e.g. 3 strongest) ----
        [$minLat, $maxLat, $minLng, $maxLng] = $this->cbdBounds();
        $cbdHubIds = \DB::table('transit_hubs')
            ->join('stops', 'transit_hubs.stop_id', '=', 'stops.stop_id')
            ->whereBetween('stops.stop_lat', [$minLat, $maxLat])
            ->whereBetween('stops.stop_long', [$minLng, $maxLng])
            ->orderBy('transit_hubs.rank')
            ->limit(3)
            ->pluck('transit_hubs.stop_id')
            ->all();

        $hubIds = array_merge($hubIds, $cbdHubIds);

        // ---- 2) Non-CBD hubs for longer trips ----
        if ($odKm >= 25.0) {
            $rows = \DB::table('transit_hubs')
                ->whereNotIn('stop_id', $cbdHubIds)
                ->where('rank', '<', 2)  // top ~2 per region
                ->pluck('stop_id')
                ->all();

            $hubIds = array_merge($hubIds, $rows);
        }

        $hubIds = array_values(array_unique(array_map('strval', $hubIds)));

        return $hubIds ? array_fill_keys($hubIds, true) : [];
    }
    /**
     * Quick bbox around the CBD polygon for faster DB queries.
     *
     * @return array [minLat, maxLat, minLng, maxLng]
     */
    private function cbdBounds(): array
    {
        $lats = array_column($this->CBD_POLY, 0); // [lat, lng]
        $lngs = array_column($this->CBD_POLY, 1);

        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);

        return [$minLat, $maxLat, $minLng, $maxLng];
    }

}


