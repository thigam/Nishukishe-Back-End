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

    public function search($originStopId, $destStopId, $limit = 24)
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

        for ($k = 1; $k <= 2; $k++) {
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
                foreach ($currentRound[$destStation] as $arrival) {
                    // Reconstruct
                    $path = $this->reconstructPathMulti($destStation, $arrival, $k, $rounds);
                    $validPaths[] = $path;
                }
            }
        }

        // Sort paths by length (fewer intermediate stations = better?)
        // Since we iterate rounds 1..2, we already prioritize fewer transfers.
        // Within same round, prioritize shorter station sequences.
        usort($validPaths, function ($a, $b) {
            return count($a) <=> count($b);
        });

        return array_slice($validPaths, 0, $limit);
    }

    private function reconstructPathMulti($destStation, $lastArrival, $round, $rounds)
    {
        $path = [];
        $path[] = [
            'from_station' => $lastArrival['from'],
            'to_station' => $destStation,
            'route_id' => $lastArrival['route']
        ];

        $curr = $lastArrival['from'];

        // Backtrack
        for ($r = $round - 1; $r > 0; $r--) {
            // Find how we got to $curr in round $r
            if (!isset($rounds[$r][$curr]))
                break; // Should not happen

            // Heuristic: Pick the arrival that minimizes distance? 
            // For now, just pick the first one.
            $arrival = $rounds[$r][$curr][0];
            $path[] = [
                'from_station' => $arrival['from'],
                'to_station' => $curr,
                'route_id' => $arrival['route']
            ];
            $curr = $arrival['from'];
        }

        return array_reverse($path);
    }

    public function expandPath($stationPath, $originStopId, $destStopId)
    {
        $detailedPath = [];
        $currentStop = $originStopId;

        foreach ($stationPath as $index => $leg) {
            $rid = $leg['route_id'];
            $sFrom = $leg['from_station'];
            $sTo = $leg['to_station'];

            // 1. Find best Boarding Stop in sFrom
            $routeStops = $this->routeStops[$rid] ?? [];

            // Filter stops that are in sFrom
            $potentialBoard = [];
            foreach ($routeStops as $sid) {
                if (($this->stopToStation[$sid] ?? '') === $sFrom) {
                    $potentialBoard[] = $sid;
                }
            }

            // Pick closest to currentStop
            $bestBoard = $this->findClosestStop($currentStop, $potentialBoard);

            // If we still have no board stop, we can't proceed with this leg
            if (!$bestBoard) {
                // Fallback: just pick ANY stop in sFrom? Or fail?
                // Let's fail this leg
                return [];
            }

            // VALIDATION: Can we walk from currentStop to bestBoard?
            $walkValid = true;
            if ($currentStop !== $bestBoard) {
                $walkTime = $this->checkWalkingEdge($currentStop, $bestBoard);
                if ($walkTime === null && $index > 0) {
                    $walkValid = false;
                }
            }

            // 2. Find best Alighting Stop in sTo
            // It must be on Route $rid, AFTER bestBoard

            $potentialAlight = [];
            $passedBoard = false;
            foreach ($routeStops as $sid) {
                if ($sid === $bestBoard)
                    $passedBoard = true;
                // Only consider stops AFTER board stop
                if ($passedBoard && $sid !== $bestBoard && ($this->stopToStation[$sid] ?? '') === $sTo) {
                    $potentialAlight[] = $sid;
                }
            }

            // If last leg, target is destStopId. Else target is "Center of Next Station" (approx)
            $target = ($index === count($stationPath) - 1) ? $destStopId : null;

            if ($target) {
                $bestAlight = $this->findClosestStop($target, $potentialAlight);
            } else {
                $bestAlight = $potentialAlight[0] ?? null;
            }

            if (!$bestAlight) {
                // Critical failure: Route goes to station sTo, but we can't find a stop in sTo after boarding?
                // This implies circular route or data inconsistency.
                return [];
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

    private function findClosestStop($targetStopId, $candidates)
    {
        if (empty($candidates))
            return null;
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
        // Query DB
        $edge = TransferEdge::where('from_stop_id', $from)->where('to_stop_id', $to)->first();
        return $edge ? $edge->walk_time_seconds : null;
    }
}
