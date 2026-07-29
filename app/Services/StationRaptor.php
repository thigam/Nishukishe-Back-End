<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\TransferEdge;
use App\Models\Stops;

class StationRaptor
{
    private $stopToStation = [];
    private $stationRoutes = [];
    private $routeStations = [];
    private $routeStops = [];
    private $stopCoords = []; // Cache for stop lat/lng
    private $directEdgeCache = [];
    private $hubEdgesCache = [];

    public function loadData()
    {
        $start = microtime(true);
        // Cache key versioning allows easy invalidation
        $cacheKey = 'station_raptor_data_v1';

        $data = Cache::remember($cacheKey, 60 * 60 * 24, function () {
            Log::info("StationRaptor: Building Cache...");

            // 1. Load Stop -> Station Map & Coords
            $members = DB::table('corr_station_members')
                ->join('stops', 'corr_station_members.stop_id', '=', 'stops.stop_id')
                ->select('corr_station_members.station_id', 'corr_station_members.stop_id', 'stops.stop_lat', 'stops.stop_long')
                ->get();

            $stopToStation = [];
            $stopCoords = [];
            foreach ($members as $m) {
                $stopToStation[$m->stop_id] = $m->station_id;
                $stopCoords[$m->stop_id] = ['lat' => $m->stop_lat, 'lng' => $m->stop_long];
            }

            // 2. Load Routes
            $routes = DB::table('sacco_routes')
                ->select('sacco_route_id', 'stop_ids')
                ->whereNotNull('stop_ids')
                ->get();

            $routeStops = [];
            $routeStations = [];
            $stationRoutes = [];

            foreach ($routes as $r) {
                $stopIds = json_decode($r->stop_ids);
                if (!is_array($stopIds) || empty($stopIds))
                    continue;

                $routeStops[$r->sacco_route_id] = $stopIds;

                $stationSequence = [];
                foreach ($stopIds as $sid) {
                    if (isset($stopToStation[$sid])) {
                        $stationId = $stopToStation[$sid];
                        if (empty($stationSequence) || end($stationSequence) !== $stationId) {
                            $stationSequence[] = $stationId;
                        }
                    }
                }

                if (count($stationSequence) > 1) {
                    $routeStations[$r->sacco_route_id] = $stationSequence;
                    foreach ($stationSequence as $stid) {
                        $stationRoutes[$stid][$r->sacco_route_id] = true;
                    }
                }
            }

            return [
                'stopToStation' => $stopToStation,
                'stopCoords' => $stopCoords,
                'routeStops' => $routeStops,
                'routeStations' => $routeStations,
                'stationRoutes' => $stationRoutes
            ];
        });

        $this->stopToStation = $data['stopToStation'];
        $this->stopCoords = $data['stopCoords'];
        $this->routeStops = $data['routeStops'];
        $this->routeStations = $data['routeStations'];
        $this->stationRoutes = $data['stationRoutes'];

        $end = microtime(true);
        Log::info("StationRaptor: Data Loaded (Cached) in " . round($end - $start, 4) . "s");
    }

    public function search($originStopId, $destStopId, $limit = 12)
    {
        $originStation = $this->stopToStation[$originStopId] ?? null;
        $destStation = $this->stopToStation[$destStopId] ?? null;

        if (!$originStation || !$destStation)
            return ['error' => "Origin/Dest not mapped."];

        return $this->searchMulti($originStation, $destStation, $limit);
    }

    private function searchMulti($originStation, $destStation, $limit)
    {
        $rounds = [];
        $rounds[0] = [$originStation => [['route' => null, 'from' => null]]];

        $validPaths = [];

        for ($k = 1; $k <= 3; $k++) {
            $prevRound = $rounds[$k - 1];
            $currentRound = [];

            $marked = array_keys($prevRound);
            $queueRoutes = [];
            foreach ($marked as $sid) {
                if (isset($this->stationRoutes[$sid])) {
                    foreach ($this->stationRoutes[$sid] as $rid => $_) {
                        $queueRoutes[$rid] = true;
                    }
                }
            }

            foreach ($queueRoutes as $rid => $_) {
                $sequence = $this->routeStations[$rid];
                $boardingStations = []; // List of potential boarding stations on this route

                foreach ($sequence as $sid) {
                    // Can we board here?
                    if (isset($prevRound[$sid])) {
                        $boardingStations[] = $sid;
                    }

                    // Can we alight here? (from ANY of the boarding stations)
                    if (!empty($boardingStations)) {
                        foreach ($boardingStations as $bSid) {
                            if ($bSid === $sid)
                                continue;

                            // Store ALL valid arrivals
                            $currentRound[$sid][] = [
                                'route' => $rid,
                                'from' => $bSid
                            ];
                        }
                    }
                }
            }

            $rounds[$k] = $currentRound;

            if (isset($currentRound[$destStation])) {
                $paths = $this->reconstructPathsRecursive($destStation, $k, $rounds);
                foreach ($paths as $path) {
                    $validPaths[] = $path;
                }
            }
        }

        // Deduplicate paths
        $uniquePaths = [];
        foreach ($validPaths as $path) {
            $sig = json_encode($path);
            $uniquePaths[$sig] = $path;
        }
        $validPaths = array_values($uniquePaths);

        // Sort paths by length (fewer intermediate stations = better?)
        // Since we iterate rounds 1..2, we already prioritize fewer transfers.
        // Within same round, prioritize shorter station sequences.
        usort($validPaths, function ($a, $b) {
            return count($a) <=> count($b);
        });

        return array_slice($validPaths, 0, $limit);
    }

    private function reconstructPathsRecursive($currStation, $round, $rounds, array $visited = [], int $maxPaths = 100)
    {
        if ($round === 0) {
            return [[]];
        }

        if (!isset($rounds[$round][$currStation])) {
            return [];
        }

        if (in_array($currStation, $visited, true)) {
            return [];
        }
        $visited[] = $currStation;

        $paths = [];
        foreach ($rounds[$round][$currStation] as $arrival) {
            if (count($paths) >= $maxPaths) {
                break;
            }
            $from = $arrival['from'];
            $route = $arrival['route'];

            $subPaths = $this->reconstructPathsRecursive($from, $round - 1, $rounds, $visited, $maxPaths);
            foreach ($subPaths as $subPath) {
                if (count($paths) >= $maxPaths) {
                    break;
                }
                $newPath = $subPath;
                $newPath[] = [
                    'from_station' => $from,
                    'to_station' => $currStation,
                    'route_id' => $route
                ];
                $paths[] = $newPath;
            }
        }
        return $paths;
    }

    public function expandPath($stationPath, $originStopId, $destStopId)
    {
        $detailedPath = [];
        $currentStop = $originStopId;

        for ($index = 0; $index < count($stationPath); $index++) {
            $leg = $stationPath[$index];
            $isLastLeg = ($index === count($stationPath) - 1);

            $rid = $leg['route_id'];
            $sFrom = $leg['from_station'];
            $sTo = $leg['to_station'];

            $routeStops = $this->routeStops[$rid] ?? [];

            // Find all potential boarding stops in sFrom
            $potentialBoard = [];
            foreach ($routeStops as $sid) {
                if (($this->stopToStation[$sid] ?? '') === $sFrom) {
                    $potentialBoard[] = $sid;
                }
            }

            if (empty($potentialBoard)) {
                return []; // Critical failure
            }

            // If it's the FIRST leg, we just pick the board stop closest to the origin.
            // For intermediate legs, we need to optimize the Alight(prev) -> Board(curr) pair.
            if ($index === 0) {
                // Heuristic: prioritize the absolute first stop of the route
                $firstCurrStop = $routeStops[0] ?? null;
                $startInStation = (($this->stopToStation[$firstCurrStop] ?? '') === $sFrom) ? $firstCurrStop : null;

                $bestBoard = $this->findClosestStop($currentStop, $potentialBoard, $startInStation);
                if (!$bestBoard)
                    return [];

                $walkValid = true;
                if ($currentStop !== $bestBoard) {
                    $walkTime = $this->checkWalkingEdge($currentStop, $bestBoard);
                    if ($walkTime === null) {
                        return []; // Cannot reach the first board stop
                    }
                }
            } else {
                // This is an intermediate transfer. 
                // We need to look back at the PREVIOUS leg and optimize the transfer.
                $prevLeg = $detailedPath[$index - 1];
                $prevRouteStops = $this->routeStops[$prevLeg['route_id']] ?? [];

                // Find all valid alight stops for the PREVIOUS leg in sFrom
                $prevAlightCandidates = [];
                $passedPrevBoard = false;
                foreach ($prevRouteStops as $sid) {
                    if ($sid === $prevLeg['from_stop'])
                        $passedPrevBoard = true;
                    if ($passedPrevBoard && $sid !== $prevLeg['from_stop'] && ($this->stopToStation[$sid] ?? '') === $sFrom) {
                        $prevAlightCandidates[] = $sid;
                    }
                }

                if (empty($prevAlightCandidates))
                    return [];

                // Now evaluate all pairs (prevAlight -> currBoard) to minimize geographic walk mapping
                $bestTransferPair = null;
                $minTransferDist = INF;

                // Identify if the previous route terminates in this station
                $lastPrevStop = end($prevRouteStops);
                $terminusInStation = (($this->stopToStation[$lastPrevStop] ?? '') === $sFrom) ? $lastPrevStop : null;

                // Identify if the CURRENT route starts in this station
                $firstCurrStop = $routeStops[0] ?? null;
                $startInStation = (($this->stopToStation[$firstCurrStop] ?? '') === $sFrom) ? $firstCurrStop : null;

                foreach ($prevAlightCandidates as $pAlight) {
                    foreach ($potentialBoard as $cBoard) {
                        // We could check $this->checkWalkingEdge($pAlight, $cBoard) here, but since
                        // they are in the same station, they are almost certainly walkable.
                        // Geographic distance is a fine heuristic to minimize walk time.

                        $t1 = $this->stopCoords[$pAlight] ?? null;
                        $t2 = $this->stopCoords[$cBoard] ?? null;
                        $dist = ($t1 && $t2) ? (($t1['lat'] - $t2['lat']) ** 2 + ($t1['lng'] - $t2['lng']) ** 2) : 0;

                        // Heuristic: heavily discount the distance if $pAlight is the terminus of the incoming route
                        if ($pAlight === $terminusInStation) {
                            $dist *= 0.1;
                        }

                        // Heuristic: heavily discount the distance if $cBoard is the START of the current route
                        if ($cBoard === $startInStation) {
                            $dist *= 0.1;
                        }

                        if ($dist < $minTransferDist) {
                            $minTransferDist = $dist;
                            $bestTransferPair = ['alight' => $pAlight, 'board' => $cBoard];
                        }
                    }
                }

                if (!$bestTransferPair)
                    return [];

                // Update the previous leg with the optimized alight stop
                $detailedPath[$index - 1]['to_stop'] = $bestTransferPair['alight'];
                $detailedPath[$index - 1]['walk_valid'] = true; // Assumed valid within station for now

                $bestBoard = $bestTransferPair['board'];
                $walkValid = true; // The walk *between* alight and board is assumed valid within the same station
            }

            // Now find the Alight stop for THIS leg...
            // If it's the last leg, we optimize it now. 
            // If it's an intermediate leg, we just pick a placeholder and the NEXT iteration will optimize it.

            $potentialAlight = [];
            $passedBoard = false;
            foreach ($routeStops as $sid) {
                if ($sid === $bestBoard)
                    $passedBoard = true;
                if ($passedBoard && $sid !== $bestBoard && ($this->stopToStation[$sid] ?? '') === $sTo) {
                    $potentialAlight[] = $sid;
                }
            }

            if (empty($potentialAlight))
                return [];

            if ($isLastLeg) {
                // Heuristic: prioritize the absolute last stop of the route
                $lastStopOfRoute = end($routeStops);
                $endInStation = (($this->stopToStation[$lastStopOfRoute] ?? '') === $sTo) ? $lastStopOfRoute : null;

                $bestAlight = $this->findClosestStop($destStopId, $potentialAlight, $endInStation);
            } else {
                // Placeholder - will be overwritten by the next iteration's transfer optimization
                $bestAlight = $potentialAlight[0];
            }

            $detailedPath[] = [
                'route_id' => $rid,
                'from_station' => $sFrom,
                'to_station' => $sTo,
                'from_stop' => $bestBoard,
                'to_stop' => $bestAlight,
                'walk_valid' => $walkValid
            ];

            $currentStop = $bestAlight;
        }

        return $detailedPath;
    }

    private function findClosestStop($targetStopId, $candidates, $priorityStopId = null)
    {
        if (empty($candidates))
            return null;

        // Hard preference: if a priority stop (route start/end) is available in the
        // candidates list, always return it directly — no distance contest.
        // This is necessary because the discount heuristic fails when another candidate
        // has a distance of exactly 0 (the origin IS that stop), which always wins.
        if ($priorityStopId && in_array($priorityStopId, $candidates)) {
            return $priorityStopId;
        }

        // No priority stop available: fall back to closest stop
        if (in_array($targetStopId, $candidates))
            return $targetStopId;

        $t = $this->stopCoords[$targetStopId] ?? null;
        if (!$t)
            return $candidates[0];

        $best = null;
        $minDist = INF;

        foreach ($candidates as $c) {
            $s = $this->stopCoords[$c] ?? null;
            if (!$s)
                continue;

            $dist = ($t['lat'] - $s['lat']) ** 2 + ($t['lng'] - $s['lng']) ** 2;

            if ($dist < $minDist) {
                $minDist = $dist;
                $best = $c;
            }
        }
        return $best;
    }

    private function checkWalkingEdge($from, $to)
    {
        if ($from === $to)
            return 0;

        $cacheKey = "{$from}_{$to}";
        if (array_key_exists($cacheKey, $this->directEdgeCache)) {
            return $this->directEdgeCache[$cacheKey];
        }

        // 1. Check DB for direct edge
        $edge = TransferEdge::where('from_stop_id', $from)->where('to_stop_id', $to)->first();
        if ($edge) {
            return $this->directEdgeCache[$cacheKey] = $edge->walk_time_seconds;
        }

        // 2. Check for Hub Intersection (synthetic edge)
        $bestTime = INF;
        if (!array_key_exists($from, $this->hubEdgesCache)) {
            $this->hubEdgesCache[$from] = TransferEdge::where('from_stop_id', $from)
                ->where('target_is_hub', true)
                ->get()
                ->keyBy('to_stop_id');
        }
        $fromHubs = $this->hubEdgesCache[$from];

        if (!$fromHubs->isEmpty()) {
            if (!array_key_exists($to, $this->hubEdgesCache)) {
                $this->hubEdgesCache[$to] = TransferEdge::where('from_stop_id', $to)
                    ->where('target_is_hub', true)
                    ->get()
                    ->keyBy('to_stop_id');
            }
            $toHubs = $this->hubEdgesCache[$to];

            if (!$toHubs->isEmpty()) {
                // Find common hubs
                $commonHubs = $fromHubs->keys()->intersect($toHubs->keys());
                if (!$commonHubs->isEmpty()) {
                    // Find the fastest path through any common hub
                    foreach ($commonHubs as $hubId) {
                        $time = $fromHubs[$hubId]->walk_time_seconds + $toHubs[$hubId]->walk_time_seconds;
                        if ($time < $bestTime) {
                            $bestTime = $time;
                        }
                    }
                }
            }
        }

        // 1800 seconds = 30 minutes WALK_CAP
        if ($bestTime <= 1800) {
            return $this->directEdgeCache[$cacheKey] = $bestTime;
        }

        // 3. Same-station fallback (Straight-line estimate during search phase)
        $sFrom = $this->stopToStation[$from] ?? null;
        $sTo = $this->stopToStation[$to] ?? null;
        if ($sFrom && $sTo && $sFrom === $sTo) {
            // Offline straight-line fallback
            $cFrom = $this->stopCoords[$from] ?? null;
            $cTo = $this->stopCoords[$to] ?? null;
            if ($cFrom && $cTo) {
                $distKm = $this->haversineKm($cFrom['lat'], $cFrom['lng'], $cTo['lat'], $cTo['lng']);
                // Assuming walking speed of 4.5 km/h
                $seconds = (int) round(($distKm / 4.5) * 3600);
                if ($seconds <= 1800) {
                    return $this->directEdgeCache[$cacheKey] = $seconds;
                }
            }
        }

        return $this->directEdgeCache[$cacheKey] = null;
    }

    private function haversineKm($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $earthRadius * $c;
    }

    public function clearCache()
    {
        $this->directEdgeCache = [];
        $this->hubEdgesCache = [];
    }
}
