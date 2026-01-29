<?php

namespace App\Http\Controllers;

use App\Models\PreCleanSaccoRoute;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\PreCleanStop;
use App\Models\PreCleanTrip;
use App\Models\PreCleanVariation;
use App\Models\PostCleanSaccoRoute;
use App\Models\PostCleanStop;
use App\Models\PostCleanTrip;
use App\Models\PostCleanVariation;
use App\Models\SaccoRoutes;
use Illuminate\Support\Facades\DB;
use App\Services\StopIdGenerator;
use App\Services\SaccoRoutePublishLogger;

class PreCleanSaccoRouteController extends Controller
{
    public function index(Request $request)
    {
        $query = PreCleanSaccoRoute::query();

        if ($request->filled('sacco_id')) {
            $query->where('sacco_id', $request->sacco_id);
        }

        if ($request->filled('route_id')) {
            $query->where('route_id', $request->route_id);
        }

        return response()->json($query->get());
    }

    /**
     * Check whether a sacco/route pair already has at least two directions
     * recorded across pre-clean, post-clean, or published routes.
     */
    public function checkDuplicatePair(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sacco_id' => 'required|string',
            'route_id' => 'required|string',
        ]);

        $directions = $this->collectDirectionIndices($data['sacco_id'], $data['route_id']);
        [$nextDirection] = $this->nextSaccoRouteKey($data['sacco_id'], $data['route_id']);

        return response()->json([
            'pair_exists' => count($directions) >= 2,
            'direction_indices' => $directions,
            'next_direction_index' => $nextDirection,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sacco_id' => 'required|string',
            'route_id' => 'required|string',   // base id (e.g., 10200010811)
            'route_number' => 'nullable|string',
            'route_start_stop' => 'required|string',
            'route_end_stop' => 'required|string',
            'coordinates' => 'array',
            'stop_ids' => 'array',
            'route_stop_times' => 'array',
            'peak_fare' => 'nullable|numeric',
            'off_peak_fare' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'notes' => 'nullable|string',
            'county_id' => 'nullable',
            'mode' => 'nullable',
            'waiting_time' => 'nullable',
            'direction_index' => 'nullable|integer',
        ]);

        // Compute direction + composite sacco_route_id up front
        [$dir, $saccoRouteId] = $this->nextSaccoRouteKey($data['sacco_id'], $data['route_id']);
        $data['direction_index'] = $data['direction_index'] ?? $dir;
        $data['sacco_route_id'] = $saccoRouteId;
        $data['status'] = $data['status'] ?? 'pending';
        $data['currency'] = strtoupper($request->input('currency', 'KES'));
        if ($request->has('route_number')) {
            $data['route_number'] = $request->input('route_number');
        }

        $pre = PreCleanSaccoRoute::create($data);
        app(SaccoRoutePublishLogger::class)->log($pre->sacco_route_id, $request->user());
        return response()->json($pre, 201);
    }

    public function show(string $id)
    {
        return response()->json(PreCleanSaccoRoute::findOrFail($id));
    }

    public function update(Request $request, string $id)
    {
        $pre = PreCleanSaccoRoute::findOrFail($id);

        $data = $request->validate([
            'route_number' => 'nullable|string',
            'route_start_stop' => 'nullable|string',
            'route_end_stop' => 'nullable|string',
            'coordinates' => 'nullable|array',
            'stop_ids' => 'nullable|array',
            'route_stop_times' => 'nullable|array',
            'peak_fare' => 'nullable|numeric',
            'off_peak_fare' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'notes' => 'nullable|string',
            'county_id' => 'nullable',
            'mode' => 'nullable|string',
            'waiting_time' => 'nullable',
            'direction_index' => 'nullable|integer',
            'status' => 'nullable|string',
        ]);

        if (array_key_exists('currency', $data)) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $pre->update($data);
        return response()->json($pre);
    }

    public function destroy(Request $request, string $id)
    {
        $request->validate(['password' => 'required|string']);
        if (!\Illuminate\Support\Facades\Hash::check($request->password, $request->user()->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        PreCleanSaccoRoute::findOrFail($id)->delete();
        return response()->json(['message' => 'deleted']);
    }

    public function approve(string $id)
    {
        // Kept for parity with your earlier flow, but now preserves IDs correctly.
        $pre = PreCleanSaccoRoute::findOrFail($id);
        $pre->status = 'cleaned';
        $pre->save();

        $post = PostCleanSaccoRoute::create([
            'pre_clean_id' => $pre->id,
            'route_id' => $pre->route_id,        // base stays base
            'sacco_route_id' => $pre->sacco_route_id,  // carry composite as-is
            'sacco_id' => $pre->sacco_id,
            'route_number' => $pre->route_number,
            'route_start_stop' => $pre->route_start_stop,
            'route_end_stop' => $pre->route_end_stop,
            'coordinates' => $pre->coordinates,
            'stop_ids' => $pre->stop_ids,
            'peak_fare' => $pre->peak_fare,
            'off_peak_fare' => $pre->off_peak_fare,
            'currency' => $pre->currency,
            'county_id' => $pre->county_id,
            'mode' => $pre->mode,
            'waiting_time' => $pre->waiting_time,
            'direction_index' => $pre->direction_index,
        ]);

        return response()->json($post);
    }

    public function reject(string $id)
    {
        $pre = PreCleanSaccoRoute::findOrFail($id);
        $pre->status = 'rejected';
        $pre->save();
        return response()->json($pre);
    }

    public function showWithStops($id)
    {
        $route = PreCleanSaccoRoute::findOrFail($id);
        $stopIds = collect(is_array($route->stop_ids) ? $route->stop_ids : [])
            ->filter(fn($id) => $id !== null && $id !== '')
            ->map(fn($id) => is_numeric($id) ? (int) $id : $id)
            ->values();

        $stopsById = $stopIds->isNotEmpty()
            ? PreCleanStop::whereIn('id', $stopIds->all())->get()
            : collect();

        $stopsByRoute = $route->sacco_route_id
            ? PreCleanStop::forRoute($route->sacco_route_id)->get()
            : collect();

        $combinedStops = $stopsById
            ->concat($stopsByRoute)
            ->unique('id')
            ->values();

        if ($stopIds->isNotEmpty()) {
            $orderedStops = collect();
            $combinedById = $combinedStops->keyBy('id');

            foreach ($stopIds as $stopId) {
                if ($combinedById->has($stopId)) {
                    $orderedStops->push($combinedById->get($stopId));
                    $combinedById->forget($stopId);
                }
            }

            foreach ($combinedStops as $stop) {
                if (!$orderedStops->contains(fn($existing) => $existing->id === $stop->id)) {
                    $orderedStops->push($stop);
                }
            }

            $stops = $orderedStops;
        } else {
            $stops = $combinedStops;
        }

        $enrichedStops = $stops->map(function ($stop) {
            $nearby = \App\Models\Stops::select('*')
                ->selectRaw(
                    '(6371 * acos(
                        cos(radians(?)) * cos(radians(stop_lat)) *
                        cos(radians(stop_long) - radians(?)) +
                        sin(radians(?)) * sin(radians(stop_lat))
                    )) AS distance',
                    [$stop->stop_lat, $stop->stop_long, $stop->stop_lat]
                )
                ->whereRaw(
                    '(6371 * acos(
                        cos(radians(?)) * cos(radians(stop_lat)) *
                        cos(radians(stop_long) - radians(?)) +
                        sin(radians(?)) * sin(radians(stop_lat))
                    )) < 1',
                    [$stop->stop_lat, $stop->stop_long, $stop->stop_lat]
                )
                ->orderBy('distance')
                ->get();

            return [
                'id' => $stop->id,
                'stop_name' => $stop->stop_name,
                'stop_lat' => $stop->stop_lat,
                'stop_long' => $stop->stop_long,
                'nearby_stops' => $nearby,
            ];
        });

        $trips = PreCleanTrip::where('sacco_route_id', $route->sacco_route_id)->get();
        $variations = PreCleanVariation::where('sacco_route_id', $route->sacco_route_id)->get();

        return response()->json([
            'route' => $route,
            'stops' => $enrichedStops,
            'trips' => $trips,
            'variations' => $variations,
        ]);
    }

    public function finalize(Request $request, string $id)
    {
        $payload = $request->validate([
            'coordinates' => 'nullable|array',
            'stop_ids' => 'nullable|array',
            // [{pre_id:int, stop_id:int, stop_name:string, stop_lat:float, stop_long:float}]
            'stop_replacements' => 'nullable|array',
            'promote_trips' => 'nullable|boolean',
            'promote_variations' => 'nullable|boolean',
            'peak_fare' => 'nullable|numeric',
            'off_peak_fare' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
        ]);

        /** @var StopIdGenerator $generator */
        $generator = app(StopIdGenerator::class);

        return DB::transaction(function () use ($id, $payload, $generator) {
            $pre = PreCleanSaccoRoute::findOrFail($id);
            $originalStopIds = is_array($pre->stop_ids) ? $pre->stop_ids : [];

            // Apply UI edits
            if (isset($payload['coordinates']))
                $pre->coordinates = $payload['coordinates'];
            if (isset($payload['stop_ids']))
                $pre->stop_ids = $payload['stop_ids'];
            if (array_key_exists('peak_fare', $payload)) {
                $pre->peak_fare = $payload['peak_fare'];
            }
            if (array_key_exists('off_peak_fare', $payload)) {
                $pre->off_peak_fare = $payload['off_peak_fare'];
            }
            if (array_key_exists('currency', $payload)) {
                $pre->currency = is_string($payload['currency'])
                    ? strtoupper($payload['currency'])
                    : $payload['currency'];
            }
            $pre->status = 'cleaned';
            $pre->save();

            // Record each pre→final stop decision
            $replacementMap = [];
            foreach ($payload['stop_replacements'] ?? [] as $replacement) {
                if (!isset($replacement['pre_id'])) {
                    continue;
                }

                $replacementMap[(string) $replacement['pre_id']] = $replacement;
            }

            $finalStopRefs = is_array($pre->stop_ids) ? array_values($pre->stop_ids) : [];
            $originalStopIds = array_values($originalStopIds);
            $stopIdLookup = [];
            $finalStopIds = [];

            foreach ($finalStopRefs as $index => $finalStopRef) {
                $sourcePreId = $originalStopIds[$index] ?? null;
                $replacement = null;

                if ($sourcePreId !== null && isset($replacementMap[(string) $sourcePreId])) {
                    $replacement = $replacementMap[(string) $sourcePreId];
                } elseif (isset($replacementMap[(string) $finalStopRef])) {
                    $replacement = $replacementMap[(string) $finalStopRef];
                }

                $preCleanId = $replacement['pre_id'] ?? $sourcePreId;

                $stopName = $replacement['stop_name'] ?? null;
                $stopLat = $replacement['stop_lat'] ?? null;
                $stopLong = $replacement['stop_long'] ?? null;

                if ($stopLat === null || $stopLong === null) {
                    $lookupId = $sourcePreId ?? $finalStopRef;
                    $stop = $lookupId !== null ? PreCleanStop::find($lookupId) : null;

                    if (!$stop && $lookupId !== $finalStopRef && $finalStopRef !== null) {
                        $stop = PreCleanStop::find($finalStopRef);
                    }

                    if ($stop) {
                        $stopName = $stopName ?? $stop->stop_name;
                        $stopLat = $stopLat ?? $stop->stop_lat;
                        $stopLong = $stopLong ?? $stop->stop_long;
                        $preCleanId = $preCleanId ?? $stop->id;
                    }
                }

                if ($stopLat === null || $stopLong === null) {
                    continue;
                }

                $generatedId = $generator->generate((float) $stopLat, (float) $stopLong);

                $postCleanStop = PostCleanStop::firstOrNew(['stop_id' => $generatedId]);

                $postCleanStop->fill([
                    'pre_clean_id' => $preCleanId,
                    'stop_id' => $generatedId,
                    'stop_name' => $stopName,
                    'stop_lat' => $stopLat,
                    'stop_long' => $stopLong,
                    'county_id' => $pre->county_id,
                    'direction_id' => $pre->direction_index,
                ]);

                $postCleanStop->attachSaccoRouteId($pre->sacco_route_id, false);
                $postCleanStop->save();

                $finalStopIds[] = $generatedId;

                foreach ([
                    $preCleanId,
                    $sourcePreId,
                    $finalStopRef,
                    $replacement['stop_id'] ?? null,
                    $generatedId,
                ] as $legacyId) {
                    if ($legacyId === null) {
                        continue;
                    }

                    $stopIdLookup[(string) $legacyId] = $generatedId;
                }
            }

            // Create post-clean row preserving ids using formatted stop identifiers
            $post = PostCleanSaccoRoute::create([
                'pre_clean_id' => $pre->id,
                'route_id' => $pre->route_id,
                'sacco_route_id' => $pre->sacco_route_id,
                'sacco_id' => $pre->sacco_id,
                'route_start_stop' => $pre->route_start_stop,
                'route_end_stop' => $pre->route_end_stop,
                'coordinates' => $pre->coordinates,
                'stop_ids' => $finalStopIds,
                'peak_fare' => $pre->peak_fare,
                'off_peak_fare' => $pre->off_peak_fare,
                'currency' => $pre->currency,
                'county_id' => $pre->county_id,
                'mode' => $pre->mode,
                'waiting_time' => $pre->waiting_time,
                'direction_index' => $pre->direction_index,
            ]);


            // Promote trips (keeps base route_id; if your trips table has sacco_route_id, set it there too)
            if (($payload['promote_trips'] ?? true) === true) {
                $preTrips = PreCleanTrip::where('sacco_route_id', $pre->sacco_route_id)->get();
                foreach ($preTrips as $pt) {
                    $stopTimes = [];
                    foreach (($pt->stop_times ?? []) as $stopIndex => $stopTime) {
                        $stopTime = is_array($stopTime) ? $stopTime : (array) $stopTime;

                        $originalStopId = $stopTime['stop_id'] ?? null;
                        $resolvedId = null;

                        if ($originalStopId !== null && isset($stopIdLookup[(string) $originalStopId])) {
                            $resolvedId = $stopIdLookup[(string) $originalStopId];
                        }

                        if ($resolvedId === null && isset($finalStopIds[$stopIndex])) {
                            $resolvedId = $finalStopIds[$stopIndex];
                        }

                        if ($resolvedId === null) {
                            $resolvedId = $originalStopId;
                        }

                        if ($resolvedId !== null) {
                            $stopTime['stop_id'] = (string) $resolvedId;
                        } else {
                            $stopTime['stop_id'] = $resolvedId;
                        }
                        $stopTimes[] = $stopTime;
                    }

                    $pt->stop_times = $stopTimes;

                    PostCleanTrip::create([
                        'pre_clean_id' => $pt->id,
                        'route_id' => $pre->route_id,       // base
                        'sacco_id' => $pre->sacco_id,
                        'trip_times' => $pt->stop_times,
                        'sacco_route_id' => $pre->sacco_route_id,
                        'day_of_week' => $pt->day_of_week ?? [],
                    ]);
                }
            }

            // Promote variations
            if (($payload['promote_variations'] ?? true) === true) {
                $preVars = PreCleanVariation::where('sacco_route_id', $pre->sacco_route_id)->get();
                foreach ($preVars as $pv) {
                    $variationStopIds = collect($pv->stop_ids ?? [])
                        ->map(function ($stopId) use ($stopIdLookup) {
                            if ($stopId === null) {
                                return $stopId;
                            }

                            $key = (string) $stopId;

                            return $stopIdLookup[$key] ?? (string) $stopId;
                        })
                        ->all();

                    PostCleanVariation::create([
                        'pre_clean_id' => $pv->id,
                        'sacco_route_id' => $post->id, // FK to post_clean_sacco_routes
                        'coordinates' => $pv->coordinates,
                        'stop_ids' => $variationStopIds,
                    ]);
                }
            }

            // Deep delete the pre-clean data (optional; matches your current behavior)
            PreCleanTrip::where('sacco_route_id', $pre->sacco_route_id)->delete();
            PreCleanVariation::where('sacco_route_id', $pre->sacco_route_id)->delete();
            if (is_array($pre->stop_ids) && count($pre->stop_ids)) {
                $stops = PreCleanStop::whereIn('id', $pre->stop_ids)->get();

                foreach ($stops as $stop) {
                    $changed = $stop->detachSaccoRouteId($pre->sacco_route_id, false);
                    $remainingRouteIds = $stop->sacco_route_ids ?? [];

                    if (empty($remainingRouteIds)) {
                        $stop->delete();
                    } elseif ($changed) {
                        $stop->save();
                    }
                }
            }
            $pre->delete();

            return response()->json([
                'message' => 'finalized',
                'post_clean_route_id' => $post->id,
                'post_clean_route_key' => $post->sacco_route_id,
            ]);
        });
    }

    public function destroyDeep(string $id)
    {
        return DB::transaction(function () use ($id) {
            $pre = PreCleanSaccoRoute::findOrFail($id);
            PreCleanTrip::where('sacco_route_id', $pre->sacco_route_id)->delete();
            PreCleanVariation::where('sacco_route_id', $pre->sacco_route_id)->delete();
            if (is_array($pre->stop_ids) && count($pre->stop_ids)) {
                $stops = PreCleanStop::whereIn('id', $pre->stop_ids)->get();

                foreach ($stops as $stop) {
                    $changed = $stop->detachSaccoRouteId($pre->sacco_route_id, false);
                    $remainingRouteIds = $stop->sacco_route_ids ?? [];

                    if (empty($remainingRouteIds)) {
                        $stop->delete();
                    } elseif ($changed) {
                        $stop->save();
                    }
                }
            }
            $pre->delete();

            return response()->json(['message' => 'deleted']);
        });
    }

    /**
     * Compute the next composite sacco_route_id and direction index for a (sacco_id, base route_id) pair.
     * Looks across BOTH pre_clean and post_clean tables to avoid collisions.
     */
    private function nextSaccoRouteKey(string $saccoId, string $baseRouteId): array
    {
        $prefix = $saccoId . '_' . $baseRouteId . '_';
        $directions = $this->collectDirectionIndices($saccoId, $baseRouteId);

        $maxDirection = empty($directions) ? 0 : max($directions);
        $directionIndex = $maxDirection + 1;
        $suffix = sprintf('%03d', $directionIndex);
        $routeKey = $prefix . $suffix;

        return [$directionIndex, $routeKey];
    }

    private function collectDirectionIndices(string $saccoId, string $baseRouteId): array
    {
        $prefix = $saccoId . '_' . $baseRouteId . '_';
        $pattern = '/^' . preg_quote($prefix, '/') . '(\d{3})$/';

        $directions = [];

        $recordDirection = function ($value) use (&$directions) {
            $direction = (int) $value;
            if ($direction > 0) {
                $directions[] = $direction;
            }
        };

        $collect = function ($rows) use (&$directions, $pattern, $recordDirection) {
            foreach ($rows as $row) {
                $recordDirection($row->direction_index ?? null);

                foreach (['sacco_route_id', 'route_id'] as $field) {
                    $candidate = $row->{$field} ?? null;
                    if (is_string($candidate) && preg_match($pattern, $candidate, $matches)) {
                        $recordDirection($matches[1]);
                    }
                }
            }
        };

        $matchLegacy = function ($query) use ($baseRouteId, $prefix) {
            return $query->where(function ($inner) use ($baseRouteId, $prefix) {
                $inner->where('route_id', $baseRouteId)
                    ->orWhere('sacco_route_id', 'like', $prefix . '%')
                    ->orWhere('route_id', 'like', $prefix . '%');
            });
        };

        $collect($matchLegacy(PreCleanSaccoRoute::where('sacco_id', $saccoId))
            ->get(['direction_index', 'sacco_route_id', 'route_id']));

        $collect($matchLegacy(PostCleanSaccoRoute::where('sacco_id', $saccoId))
            ->get(['direction_index', 'sacco_route_id', 'route_id']));

        $collect($matchLegacy(SaccoRoutes::where('sacco_id', $saccoId))
            ->get(['sacco_route_id', 'route_id']));

        $directions = array_values(array_unique($directions));
        sort($directions, SORT_NUMERIC);

        return $directions;
    }
}

