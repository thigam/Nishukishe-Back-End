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

        // 2. Fetch Sacco Routes
        // We only need the geometry (coordinates) and basic info
        $routes = SaccoRoutes::with('sacco:sacco_id,sacco_name,sacco_logo')
            ->select('sacco_route_id', 'sacco_id', 'route_id', 'coordinates')
            ->get()
            ->map(function ($route) {
                return [
                    'id' => $route->sacco_route_id,
                    'sacco_name' => $route->sacco->sacco_name ?? 'Unknown',
                    'route_number' => $route->route_id, // or route->route->route_number if available
                    'coordinates' => $route->coordinates, // Assuming this is cast to array in model
                ];
            });

        return response()->json([
            'stations' => $stations,
            'routes' => $routes,
        ]);
    }
}
