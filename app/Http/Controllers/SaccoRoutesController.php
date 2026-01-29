<?php

namespace App\Http\Controllers;
use App\Models\PostCleanSaccoRoute;
use App\Models\PostCleanStop;
use App\Models\PostCleanTrip;
use App\Models\PostCleanVariation;
use App\Models\PreCleanSaccoRoute;
use App\Models\PreCleanStop;
use App\Models\PreCleanTrip;
use App\Models\PreCleanVariation;
use App\Models\Route as BaseRoute;
use App\Models\RouteVerification;
use App\Models\Sacco;
use App\Models\SaccoRoutes;
use App\Models\Stops;
use App\Models\Trip;
use App\Models\Variation;
use App\Services\FareCalculator;
use App\Services\SaccoRoutePublishLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SaccoRoutesController extends Controller
{
    private FareCalculator $fareCalculator;

    public function __construct(FareCalculator $fareCalculator)
    {
        $this->fareCalculator = $fareCalculator;
        $this->middleware('verify.tier:route_creation')->only('addNewSaccoRoute');
    }

    public function index(Request $request): JsonResponse
    {
        $routes = SaccoRoutes::all();
        return response()->json($routes);
    }

    public function showByRoute($route): JsonResponse
    {
        $route = saccoRoutes::where('route_id', $route)->first();
        if ($route) {
            return response()->json($route);
        }
        return response()->json(['message' => 'Route not found'], 404);
    }

    public function searchByStops(Request $request): JsonResponse
    {
        $boardId = $request->input('start_stop');
        $alightId = $request->input('end_stop');
        $departureTime = $request->input('departure_time') ? Carbon::parse($request->input('departure_time')) : null;

        $candidates = SaccoRoutes::query()
            ->whereJsonContains('stop_ids', $boardId)
            ->whereJsonContains('stop_ids', $alightId)
            ->get();

        $results = [];

        foreach ($candidates as $sr) {
            $ids = $sr->stop_ids;
            $iBoard = array_search($boardId, $ids);
            $iAlight = array_search($alightId, $ids);

            if ($iBoard !== false && $iAlight !== false && $iBoard < $iAlight) {
                $routeMeta = $sr->route;
                $boardStop = Stops::find($boardId);
                $alightStop = Stops::find($alightId);

                $boardingInCbd = $boardStop ? $this->fareCalculator->isInCbd((float) $boardStop->stop_lat, (float) $boardStop->stop_long) : false;
                $alightingInCbd = $alightStop ? $this->fareCalculator->isInCbd((float) $alightStop->stop_lat, (float) $alightStop->stop_long) : false;

                $distanceKm = null;
                if ($boardStop && $alightStop) {
                    $distanceKm = $this->fareCalculator->distanceBetween(
                        (float) $boardStop->stop_lat,
                        (float) $boardStop->stop_long,
                        (float) $alightStop->stop_lat,
                        (float) $alightStop->stop_long,
                    );
                }

                $totalRouteDistanceKm = $this->routeDistanceKm($ids);

                $fareBreakdown = $this->fareCalculator->calculate(
                    $distanceKm ?? 0.0,
                    $totalRouteDistanceKm,
                    $departureTime,
                    false,
                    $sr->peak_fare,
                    $sr->off_peak_fare,
                    $boardingInCbd,
                    $alightingInCbd
                );

                $results[] = [
                    'sacco_id' => $sr->sacco_id,
                    'route_id' => $sr->route_id,
                    'route_number' => $routeMeta->route_number,
                    'board_stop' => $boardStop,
                    'alight_stop' => $alightStop,
                    'coordinates' => array_slice($sr->coordinates, $iBoard, $iAlight - $iBoard + 1),
                    'fare' => $fareBreakdown['fare'],
                    'off_peak_fare' => $fareBreakdown['off_peak_fare'],
                    'peak_fare' => $fareBreakdown['peak_fare'],
                    'currency' => $sr->currency ?? 'KES',
                    'distance_km' => $fareBreakdown['distance_km'],
                    'requires_manual_fare' => $fareBreakdown['requires_manual_fare'],
                ];
            }
        }

        if (empty($results)) {
            return response()->json(['message' => 'No matching routes'], 404);
        }
        return response()->json($results);
    }

    public function showBySacco($sacco): JsonResponse
    {
        $routes = SaccoRoutes::with('route')
            ->where('sacco_id', $sacco)
            ->get();

        if ($routes->isNotEmpty()) {
            return response()->json($routes);
        }

        return response()->json(['message' => 'No routes found for this sacco'], 404);
    }

    public function directions(Request $request, string $routeId): JsonResponse
    {
        $saccoId = $request->query('sacco_id');

        if (!is_string($saccoId) || trim($saccoId) === '') {
            return response()->json(['message' => 'Missing sacco_id'], 422);
        }

        $snapshots = $this->loadRouteSnapshots($saccoId, $routeId);

        if (empty($snapshots)) {
            return response()->json(['message' => 'Route not found'], 404);
        }

        $saccoRouteIds = array_values(array_filter(array_map(
            fn($snapshot) => $snapshot['sacco_route_id'] ?? null,
            $snapshots
        )));

        $preClean = $saccoRouteIds
            ? PreCleanSaccoRoute::whereIn('sacco_route_id', $saccoRouteIds)
                ->get(['id', 'sacco_route_id', 'notes', 'status', 'updated_at'])
            : collect();

        $verification = RouteVerification::where('route_id', $routeId)
            ->where('sacco_id', $saccoId)
            ->first();

        $routeMeta = $this->resolveRouteMeta($snapshots, $routeId, $saccoId);

        return response()->json([
            'route' => $routeMeta,
            'directions' => array_map(function (array $snapshot) {
                $label = $this->directionLabel(
                    $snapshot['direction_index'] ?? null,
                    $snapshot['sacco_route_id'] ?? ''
                );

                return [
                    'sacco_route_id' => $snapshot['sacco_route_id'],
                    'direction_index' => $snapshot['direction_index'],
                    'label' => $label,
                    'coordinates' => $snapshot['coordinates'] ?? [],
                    'stops' => array_map(function (array $stop) {
                        return [
                            'id' => $stop['id'] ?? null,
                            'stop_name' => $stop['stop_name'] ?? null,
                            'stop_lat' => $stop['stop_lat'] ?? null,
                            'stop_long' => $stop['stop_long'] ?? null,
                        ];
                    }, $snapshot['stops'] ?? []),
                ];
            }, $snapshots),
            'pre_clean' => $preClean,
            'verification' => $verification,
        ]);
    }

    public function requestCleanup(Request $request, string $routeId): JsonResponse
    {
        $payload = $request->validate([
            'sacco_id' => 'required|string',
            'notes' => 'nullable|string',
            'verified' => 'sometimes|boolean',
            'sacco_route_ids' => 'nullable|array',
        ]);

        $saccoId = trim($payload['sacco_id']);
        $notes = trim((string) ($payload['notes'] ?? ''));
        $verified = (bool) ($payload['verified'] ?? false);
        $requestedRouteIds = collect($payload['sacco_route_ids'] ?? [])
            ->map(fn($id) => is_string($id) ? trim($id) : (string) $id)
            ->filter()
            ->values()
            ->all();

        $snapshots = $this->loadRouteSnapshots($saccoId, $routeId);

        if (empty($snapshots)) {
            return response()->json(['message' => 'Route not found'], 404);
        }

        if (!empty($requestedRouteIds)) {
            $snapshots = array_values(array_filter($snapshots, function (array $snapshot) use ($requestedRouteIds) {
                return in_array($snapshot['sacco_route_id'] ?? null, $requestedRouteIds, true);
            }));

            if (empty($snapshots)) {
                return response()->json(['message' => 'No matching directions found for request'], 404);
            }
        }

        $results = DB::transaction(function () use ($snapshots, $notes, $verified) {
            $created = [];

            foreach ($snapshots as $snapshot) {
                $created[] = $this->cloneRouteSnapshot($snapshot, $notes, $verified);
            }

            return $created;
        });

        return response()->json([
            'message' => 'queued',
            'routes' => $results,
        ], 201);
    }

    public function verifyRoute(Request $request, string $routeId): JsonResponse
    {
        $payload = $request->validate([
            'sacco_id' => 'required|string',
            'sacco_route_ids' => 'nullable|array',
            'note' => 'nullable|string',
        ]);

        $user = $request->user();

        $verification = RouteVerification::updateOrCreate(
            [
                'route_id' => $routeId,
                'sacco_id' => trim($payload['sacco_id']),
            ],
            [
                'sacco_route_ids' => collect($payload['sacco_route_ids'] ?? [])
                    ->map(fn($id) => is_string($id) ? trim($id) : (string) $id)
                    ->filter()
                    ->values()
                    ->all(),
                'notes' => $payload['note'] ?? null,
                'verified_at' => Carbon::now(),
                'verified_by' => $user?->id,
                'verified_role' => $user?->role,
            ]
        );

        return response()->json($verification);
    }

    public function showVerification(Request $request, string $routeId): JsonResponse
    {
        $saccoId = $request->query('sacco_id');

        if (!is_string($saccoId) || trim($saccoId) === '') {
            return response()->json(['message' => 'Missing sacco_id'], 422);
        }

        $verification = RouteVerification::where('route_id', $routeId)
            ->where('sacco_id', $saccoId)
            ->first();

        if (!$verification) {
            return response()->json(['message' => 'Not verified'], 404);
        }

        return response()->json($verification);
    }

    private function loadRouteSnapshots(string $saccoId, string $routeId): array
    {
        $postClean = PostCleanSaccoRoute::where('sacco_id', $saccoId)
            ->where('route_id', $routeId)
            ->orderBy('direction_index')
            ->get();



        if ($postClean->isNotEmpty()) {
            return $postClean->map(function (PostCleanSaccoRoute $route) {
                return [
                    'source' => 'post_clean',
                    'sacco_route_id' => $route->sacco_route_id,
                    'route_id' => $route->route_id,
                    'sacco_id' => $route->sacco_id,
                    'route_number' => $route->route_number,
                    'route_start_stop' => $route->route_start_stop,
                    'route_end_stop' => $route->route_end_stop,
                    'coordinates' => $route->coordinates ?? [],
                    'stop_ids' => $route->stop_ids ?? [],
                    'stops' => $this->loadStopsForPostClean($route),
                    'peak_fare' => $route->peak_fare,
                    'off_peak_fare' => $route->off_peak_fare,
                    'currency' => $route->currency,
                    'county_id' => $route->county_id,
                    'mode' => $route->mode,
                    'waiting_time' => $route->waiting_time,
                    'direction_index' => $route->direction_index,
                ];
            })->values()->all();
        }

        $finalRoutes = SaccoRoutes::with('route')
            ->where('sacco_id', $saccoId)
            ->where('route_id', $routeId)
            ->orderBy('sacco_route_id')
            ->get();

        if ($finalRoutes->isNotEmpty()) {
            return $finalRoutes->map(function (SaccoRoutes $route) {
                $base = $route->route ?? BaseRoute::find($route->route_id);

                return [
                    'source' => 'sacco_routes',
                    'sacco_route_id' => $route->sacco_route_id,
                    'route_id' => $route->route_id,
                    'sacco_id' => $route->sacco_id,
                    'route_number' => $base?->route_number,
                    'route_start_stop' => $base?->route_start_stop,
                    'route_end_stop' => $base?->route_end_stop,
                    'coordinates' => $route->coordinates ?? [],
                    'stop_ids' => $route->stop_ids ?? [],
                    'stops' => $this->loadStopsForFinal($route),
                    'peak_fare' => $route->peak_fare ?? null,
                    'off_peak_fare' => $route->off_peak_fare ?? null,
                    'currency' => $route->currency ?? 'KES',
                    'county_id' => $route->county_id ?? null,
                    'mode' => $route->mode ?? null,
                    'waiting_time' => $route->waiting_time ?? null,
                    'direction_index' => $this->deriveDirectionIndex($route->sacco_route_id, $route->direction_index ?? null),
                ];
            })->values()->all();
        }

        return [];
    }

    private function resolveRouteMeta(array $snapshots, string $routeId, string $saccoId): array
    {
        $first = $snapshots[0] ?? [];

        $routeNumber = $first['route_number'] ?? null;
        $start = $first['route_start_stop'] ?? null;
        $end = $first['route_end_stop'] ?? null;

        if ($routeNumber === null || $start === null || $end === null) {
            $base = BaseRoute::find($routeId);
            if ($base) {
                $routeNumber = $routeNumber ?? $base->route_number;
                $start = $start ?? $base->route_start_stop;
                $end = $end ?? $base->route_end_stop;
            }
        }

        return [
            'route_id' => $routeId,
            'sacco_id' => $saccoId,
            'route_number' => $routeNumber,
            'route_start_stop' => $start,
            'route_end_stop' => $end,
        ];
    }
    private function loadStopsForPostClean(PostCleanSaccoRoute $route): array
    {
        $stopIds = array_values(array_filter(is_array($route->stop_ids) ? $route->stop_ids : []));

        if (empty($stopIds)) {
            return [];
        }

        $stops = PostCleanStop::whereJsonContains('sacco_route_ids', $route->sacco_route_id)
            ->get()
            ->keyBy(fn($stop) => (string) $stop->stop_id);

        $ordered = [];
        foreach ($stopIds as $stopId) {
            $key = (string) $stopId;
            $stop = $stops->get($key) ?: $stops->firstWhere('stop_id', $stopId);

            if (!$stop) {
                $fallback = Stops::find($stopId);
                if (!$fallback) {
                    continue;
                }

                $ordered[] = [
                    'id' => $key,
                    'stop_name' => $fallback->stop_name,
                    'stop_lat' => $fallback->stop_lat,
                    'stop_long' => $fallback->stop_long,
                    'county_id' => $fallback->county_id,
                    'direction_id' => $fallback->direction_id,
                ];
                continue;
            }

            $ordered[] = [
                'id' => $key,
                'stop_name' => $stop->stop_name,
                'stop_lat' => $stop->stop_lat,
                'stop_long' => $stop->stop_long,
                'county_id' => $stop->county_id,
                'direction_id' => $stop->direction_id,
            ];
        }

        return $ordered;
    }

    private function loadStopsForFinal(SaccoRoutes $route): array
    {
        $stopIds = array_values(array_filter(is_array($route->stop_ids) ? $route->stop_ids : []));

        if (empty($stopIds)) {
            return [];
        }

        $stops = Stops::whereIn('stop_id', $stopIds)
            ->get()
            ->keyBy(fn($stop) => (string) $stop->stop_id);

        $ordered = [];
        foreach ($stopIds as $stopId) {
            $key = (string) $stopId;
            $stop = $stops->get($key) ?: $stops->firstWhere('stop_id', $stopId);

            if (!$stop) {
                continue;
            }

            $ordered[] = [
                'id' => $key,
                'stop_name' => $stop->stop_name,
                'stop_lat' => $stop->stop_lat,
                'stop_long' => $stop->stop_long,
                'county_id' => $stop->county_id,
                'direction_id' => $stop->direction_id,
            ];
        }

        return $ordered;
    }

    private function cloneRouteSnapshot(array $snapshot, string $notes, bool $verified): array
    {
        $saccoRouteId = $snapshot['sacco_route_id'] ?? null;

        if (!is_string($saccoRouteId) || trim($saccoRouteId) === '') {
            return [
                'sacco_route_id' => $saccoRouteId,
                'skipped' => true,
                'reason' => 'missing_sacco_route_id',
            ];
        }

        $pre = PreCleanSaccoRoute::firstOrNew(['sacco_route_id' => $saccoRouteId]);
        $creating = !$pre->exists;

        $pre->sacco_id = $snapshot['sacco_id'] ?? $pre->sacco_id;
        $pre->route_id = $snapshot['route_id'] ?? $pre->route_id;

        if (!empty($snapshot['route_number'])) {
            $pre->route_number = $snapshot['route_number'];
        }
        if (!empty($snapshot['route_start_stop'])) {
            $pre->route_start_stop = $snapshot['route_start_stop'];
        }
        if (!empty($snapshot['route_end_stop'])) {
            $pre->route_end_stop = $snapshot['route_end_stop'];
        }

        $pre->coordinates = $snapshot['coordinates'] ?? [];
        $pre->peak_fare = $snapshot['peak_fare'] ?? $pre->peak_fare;
        $pre->off_peak_fare = $snapshot['off_peak_fare'] ?? $pre->off_peak_fare;
        $pre->county_id = $snapshot['county_id'] ?? $pre->county_id;
        $pre->mode = $snapshot['mode'] ?? $pre->mode;
        $pre->waiting_time = $snapshot['waiting_time'] ?? $pre->waiting_time;
        $pre->direction_index = $this->deriveDirectionIndex($saccoRouteId, $snapshot['direction_index'] ?? null);
        $pre->status = $verified ? 'verified' : 'pending';

        if ($creating) {
            if ($notes !== '') {
                $pre->notes = $notes;
            }
        } elseif ($notes !== '') {
            $existingNotes = (string) $pre->notes;

            if (trim($existingNotes) === '') {
                $pre->notes = $notes;
            } else {
                $pre->notes = rtrim($existingNotes) . PHP_EOL . PHP_EOL . '---' . PHP_EOL . PHP_EOL . $notes;
            }
        }

        $pre->save();

        if ($creating) {
            PreCleanStop::whereJsonContains('sacco_route_ids', $saccoRouteId)->delete();
            PreCleanTrip::where('sacco_route_id', $saccoRouteId)->delete();
            PreCleanVariation::where('sacco_route_id', $saccoRouteId)->delete();

            $stopIdMap = [];
            $newStopIds = [];

            foreach ($snapshot['stops'] ?? [] as $stop) {
                $stopName = $stop['stop_name'] ?? null;
                if ($stopName === null) {
                    continue;
                }

                $preStop = PreCleanStop::create([
                    'sacco_route_ids' => [$saccoRouteId],
                    'stop_name' => $stopName,
                    'stop_lat' => isset($stop['stop_lat']) ? (float) $stop['stop_lat'] : 0,
                    'stop_long' => isset($stop['stop_long']) ? (float) $stop['stop_long'] : 0,
                    'county_id' => $stop['county_id'] ?? null,
                    'direction_id' => $stop['direction_id'] ?? null,
                    'status' => 'pending',
                ]);

                $originalId = (string) ($stop['id'] ?? '');
                $newStopIds[] = $preStop->id;
            }

            $pre->stop_ids = $newStopIds;
            $pre->save();

            $this->cloneTrips($snapshot, $saccoRouteId, $stopIdMap);
            $this->cloneVariations($snapshot, $saccoRouteId, $stopIdMap);
        }

        return [
            'pre_clean_id' => $pre->id,
            'sacco_route_id' => $saccoRouteId,
            'created' => $creating,
        ];
    }

    private function cloneTrips(array $snapshot, string $saccoRouteId, array $stopIdMap): void
    {
        $source = $snapshot['source'] ?? 'post_clean';

        $trips = $source === 'sacco_routes'
            ? Trip::where('sacco_route_id', $saccoRouteId)->get()
            : PostCleanTrip::where('sacco_route_id', $saccoRouteId)->get();

        foreach ($trips as $trip) {
            $stopTimes = [];

            foreach ((array) ($trip->stop_times ?? []) as $time) {
                $entry = (array) $time;
                $key = isset($entry['stop_id']) ? (string) $entry['stop_id'] : null;

                if ($key !== null && isset($stopIdMap[$key])) {
                    $entry['stop_id'] = $stopIdMap[$key];
                }

                $stopTimes[] = $entry;
            }

            PreCleanTrip::create([
                'sacco_route_id' => $saccoRouteId,
                'stop_times' => $stopTimes,
                'day_of_week' => (array) ($trip->day_of_week ?? []),
            ]);
        }
    }

    private function cloneVariations(array $snapshot, string $saccoRouteId, array $stopIdMap): void
    {
        $source = $snapshot['source'] ?? 'post_clean';

        $variations = $source === 'sacco_routes'
            ? Variation::where('sacco_route_id', $saccoRouteId)->get()
            : PostCleanVariation::where('sacco_route_id', $saccoRouteId)->get();

        foreach ($variations as $variation) {
            $stopIds = [];
            foreach ((array) ($variation->stop_ids ?? []) as $stopId) {
                $key = (string) $stopId;
                $stopIds[] = $stopIdMap[$key] ?? $stopId;
            }

            PreCleanVariation::create([
                'sacco_route_id' => $saccoRouteId,
                'coordinates' => $variation->coordinates ?? [],
                'stop_ids' => $stopIds,
                'status' => 'pending',
            ]);
        }
    }

    private function directionLabel(?int $index, string $saccoRouteId): string
    {
        $resolved = $this->deriveDirectionIndex($saccoRouteId, $index);

        return match ($resolved) {
            2 => 'reverse',
            1 => 'forward',
            null => 'direction',
            default => 'direction ' . $resolved,
        };
    }

    private function deriveDirectionIndex(?string $saccoRouteId, ?int $directionIndex = null): ?int
    {
        if (is_int($directionIndex) && $directionIndex > 0) {
            return $directionIndex;
        }

        if (!is_string($saccoRouteId)) {
            return null;
        }

        if (preg_match('/_(\d{3})$/', $saccoRouteId, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Legacy entry-point used by your Create page to seed a pre-clean record.
     * Now generates sacco_route_id at creation so it matches the format used in sacco_routes.
     */
    public function addNewSaccoRoute(Request $request): JsonResponse
    {
        $request->merge(json_decode($request->getContent(), true) ?? []);

        $validator = Validator::make($request->all(), [
            'sacco_id' => 'required|string',
            'route_number' => 'required|string',
            'route_id' => 'required|string', // base id (kept from your payload)
            'route_start_stop' => 'required|string',
            'route_end_stop' => 'required|string',
            'stop_ids' => 'required|array',
            'coordinates' => 'nullable|array',
            'peak_fare' => 'nullable|numeric',
            'off_peak_fare' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'county_id' => 'nullable|integer',
            'mode' => 'nullable|string',
            'waiting_time' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Compute direction and composite (shared with other controller)
        [$dir, $saccoRouteId] = $this->nextSaccoRouteKey(
            $request->sacco_id,
            $request->route_id
        );

        // Persist a PRE-CLEAN row (this is what your UI expects)
        $route = PreCleanSaccoRoute::create([
            'sacco_id' => $request->sacco_id,
            'route_number' => $request->route_number,
            'route_id' => $request->route_id,   // base
            'sacco_route_id' => $saccoRouteId,        // composite
            'route_start_stop' => $request->route_start_stop,
            'route_end_stop' => $request->route_end_stop,
            'stop_ids' => $request->stop_ids,
            'coordinates' => $request->input('coordinates', []),
            'peak_fare' => $request->input('peak_fare', 100),
            'off_peak_fare' => $request->input('off_peak_fare', 100),
            'currency' => strtoupper($request->input('currency', 'KES')),
            'county_id' => $request->input('county_id', null),
            'mode' => $request->input('mode', 'bus'),
            'waiting_time' => $request->input('waiting_time', 5),
            'notes' => $request->input('notes', null),
            'status' => 'pending',
            'direction_index' => $dir,
        ]);

        app(SaccoRoutePublishLogger::class)->log($route->sacco_route_id, $request->user());

        return response()->json($route, 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $route = saccoRoutes::find($id);
        if ($route) {
            $route->route_name = $request->input('route_name');
            $route->sacco_id = $request->input('sacco_id');
            $route->save();

            return response()->json($route);
        }
        return response()->json(['message' => 'Route not found'], 404);
    }

    public function delete($id): JsonResponse
    {
        $route = Routes::find($id);
        if ($route) {
            $route->delete();
            return response()->json(['message' => 'Route deleted successfully']);
        }
        return response()->json(['message' => 'Route not found'], 407);
    }

    public function searchByCoordinates(Request $request): JsonResponse
    {
        \Log::info('searchByCoordinates called', [
            'start_lat' => $request->input('start_lat'),
            'start_lng' => $request->input('start_lng'),
            'end_lat' => $request->input('end_lat'),
            'end_lng' => $request->input('end_lng'),
        ]);

        try {
            $request->validate([
                'start_lat' => 'required|numeric',
                'start_lng' => 'required|numeric',
                'end_lat' => 'required|numeric',
                'end_lng' => 'required|numeric',
                'depart_after' => 'nullable|date',
            ]);

            [$slat, $slng, $elat, $elng] = [
                $request->start_lat,
                $request->start_lng,
                $request->end_lat,
                $request->end_lng,
            ];

            $departAfter = $request->input('depart_after');
            $departTime = null;
            if ($departAfter) {
                try {
                    $departTime = Carbon::parse($departAfter, 'Africa/Nairobi')->setTimezone('Africa/Nairobi');
                } catch (\Throwable $e) {
                    $departTime = Carbon::now('Africa/Nairobi');
                }
            } else {
                $departTime = Carbon::now('Africa/Nairobi');
            }

            $isEventDay = $request->boolean('is_event_day', false);

            $expr = "( 6371 * acos(
                cos(radians(?)) * cos(radians(stop_lat)) *
                cos(radians(stop_long) - radians(?)) +
                sin(radians(?)) * sin(radians(stop_lat))
            ))";
            $bindings = [$slat, $slng, $slat];
            $startStops = Stops::selectRaw(
                "stop_id, stop_name, stop_lat, stop_long, {$expr} AS distance",
                $bindings
            )
                ->whereRaw("{$expr} <= ?", array_merge($bindings, [7]))
                ->orderBy('distance')
                ->limit(30)
                ->get();

            $bindings = [$elat, $elng, $elat];
            $endStops = Stops::selectRaw(
                "stop_id, stop_name, stop_lat, stop_long, {$expr} AS distance",
                $bindings
            )
                ->whereRaw("{$expr} <= ?", array_merge($bindings, [7]))
                ->orderBy('distance')
                ->limit(30)
                ->get();

            $startRouteIds = SaccoRoutes::query()
                ->where(function ($q) use ($startStops) {
                    foreach ($startStops->pluck('stop_id') as $sid) {
                        $q->orWhereJsonContains('stop_ids', $sid);
                    }
                })
                ->pluck('route_id')
                ->unique()
                ->toArray();

            $endRouteIds = SaccoRoutes::query()
                ->where(function ($q) use ($endStops) {
                    foreach ($endStops->pluck('stop_id') as $sid) {
                        $q->orWhereJsonContains('stop_ids', $sid);
                    }
                })
                ->pluck('route_id')
                ->unique()
                ->toArray();

            $commonIds = array_intersect($startRouteIds, $endRouteIds);

            $results = [];
            $routes = SaccoRoutes::with(['sacco', 'route'])
                ->whereIn('route_id', $commonIds)
                ->get();

            foreach ($routes as $sr) {
                $board = $startStops->filter(fn($s) => in_array($s->stop_id, $sr->stop_ids))
                    ->sortBy('distance')
                    ->first();
                $alight = $endStops->filter(fn($s) => in_array($s->stop_id, $sr->stop_ids))
                    ->sortBy('distance')
                    ->first();

                if (!$board || !$alight) {
                    continue;
                }

                $blat = (float) $board->stop_lat;
                $blng = (float) $board->stop_long;
                $alat = (float) $alight->stop_lat;
                $alng = (float) $alight->stop_long;

                $boardIdx = null;
                $bestB = INF;
                $alightIdx = null;
                $bestA = INF;
                foreach ($sr->coordinates as $i => [$lng, $lat]) {
                    $dB = ($lng - $blat) ** 2 + ($lat - $blng) ** 2;
                    if ($dB < $bestB) {
                        $bestB = $dB;
                        $boardIdx = $i;
                    }

                    $dA = ($lng - $alat) ** 2 + ($lat - $alng) ** 2;
                    if ($dA < $bestA) {
                        $bestA = $dA;
                        $alightIdx = $i;
                    }
                }

                if ($boardIdx !== null && $alightIdx !== null && $boardIdx < $alightIdx) {
                    $segmentCoords = array_slice(
                        $sr->coordinates,
                        $boardIdx,
                        $alightIdx - $boardIdx + 1
                    );
                } else {
                    $segmentCoords = $sr->coordinates;
                }

                $segmentDistanceKm = $this->fareCalculator->distanceBetween(
                    (float) $board->stop_lat,
                    (float) $board->stop_long,
                    (float) $alight->stop_lat,
                    (float) $alight->stop_long
                );

                $fareBreakdown = $this->fareCalculator->calculate(
                    $segmentDistanceKm,
                    $this->routeDistanceKm($sr->stop_ids ?? []),
                    $departTime,
                    $isEventDay,
                    $sr->peak_fare,
                    $sr->off_peak_fare,
                    $this->fareCalculator->isInCbd((float) $board->stop_lat, (float) $board->stop_long),
                    $this->fareCalculator->isInCbd((float) $alight->stop_lat, (float) $alight->stop_long)
                );

                $results[] = [
                    'sacco_id' => $sr->sacco_id,
                    'sacco_name' => $sr->sacco->sacco_name,
                    'route_id' => $sr->route_id,
                    'route_number' => $sr->route->route_number,
                    'route_name' => $sr->route->route_start_stop . ' – ' . $sr->route->route_end_stop,
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
                    'currency' => $sr->currency ?? 'KES',
                    'distance_km' => (float) $fareBreakdown['distance_km'],
                    'requires_manual_fare' => (bool) $fareBreakdown['requires_manual_fare'],
                    'coordinates' => $segmentCoords,
                ];
            }

            $results = array_slice($results, 0, 12);
            return response()->json($results);

        } catch (\Throwable $e) {
            \Log::error('searchByCoordinates error: ' . $e->getMessage(), [
                'stack' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function routeDistanceKm(array $stopIds): ?float
    {
        if (count($stopIds) < 2) {
            return null;
        }

        $firstStop = Stops::find($stopIds[0]);
        $lastStop = Stops::find($stopIds[count($stopIds) - 1]);

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

    private function nextSaccoRouteKey(string $saccoId, string $baseRouteId): array
    {
        $prefix = $saccoId . '_' . $baseRouteId . '_';
        $pattern = '/^' . preg_quote($prefix, '/') . '(\d{3})$/';

        $maxDirection = 0;


        $collectMax = function ($rows) use (&$maxDirection, $pattern) {
            foreach ($rows as $row) {
                $direction = (int) ($row->direction_index ?? 0);
                if ($direction > $maxDirection) {
                    $maxDirection = $direction;
                }

                $compositeId = $row->sacco_route_id ?? null;
                if (is_string($compositeId) && preg_match($pattern, $compositeId, $matches)) {
                    $suffix = (int) $matches[1];
                    if ($suffix > $maxDirection) {
                        $maxDirection = $suffix;
                    }
                }
            }
        };

        $collectMax(PreCleanSaccoRoute::where('sacco_id', $saccoId)
            ->where('route_id', $baseRouteId)
            ->get(['direction_index', 'sacco_route_id']));

        $collectMax(PostCleanSaccoRoute::where('sacco_id', $saccoId)
            ->where('route_id', $baseRouteId)
            ->get(['direction_index', 'sacco_route_id']));

        $collectMax(SaccoRoutes::where('sacco_id', $saccoId)
            ->where('route_id', $baseRouteId)
            ->get(['sacco_route_id']));


        $directionIndex = $maxDirection + 1;
        $suffix = sprintf('%03d', $directionIndex);
        $routeKey = $prefix . $suffix;

        return [$directionIndex, $routeKey];
    }
}

