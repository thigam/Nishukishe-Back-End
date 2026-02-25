<?php

namespace App\Http\Controllers;

use App\Models\CorridorStation;
use App\Models\SaccoRoutes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceMapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // 1. Fetch Stations with Members (Stops)
        // We need the member stops to visualize the "region"
        $stations = CorridorStation::with(['members.stop:stop_id,stop_lat,stop_long'])
            ->get()
            ->map(function ($station) {
                return [
                    'id' => $station->station_id,
                    'lat' => $station->lat,
                    'lng' => $station->lng,
                    'degree' => $station->route_degree,
                    'members' => $station->members->map(function ($m) {
                        return [
                            'stop_id' => $m->stop_id,
                            'lat' => (float) $m->stop->stop_lat,
                            'lng' => (float) $m->stop->stop_long,
                        ];
                    }),
                ];
            });

        // Build a stop_id -> station_id lookup
        $stopToStation = \App\Models\CorridorStationMember::pluck('station_id', 'stop_id')->toArray();

        // 2. Fetch Sacco Routes
        // We only need the geometry (coordinates) and basic info, plus stop_ids to map to stations
        $routes = SaccoRoutes::with('sacco:sacco_id,sacco_name,sacco_logo')
            ->select('sacco_route_id', 'sacco_id', 'route_id', 'coordinates', 'stop_ids')
            ->get()
            ->map(function ($route) use ($stopToStation) {
                $stationIds = [];
                if (is_array($route->stop_ids)) {
                    foreach ($route->stop_ids as $stopId) {
                        if (isset($stopToStation[$stopId])) {
                            $stationIds[] = $stopToStation[$stopId];
                        }
                    }
                }

                return [
                    'id' => $route->sacco_route_id,
                    'sacco_name' => $route->sacco->sacco_name ?? 'Unknown',
                    'route_number' => $route->route_id, // or route->route->route_number if available
                    'coordinates' => $route->coordinates, // Assuming this is cast to array in model
                    'station_ids' => array_values(array_unique($stationIds)),
                ];
            });

        return response()->json([
            'stations' => $stations,
            'routes' => $routes,
        ]);
    }
}
