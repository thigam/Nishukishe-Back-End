<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DriverLog;
use App\Models\SaccoRoute;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

class DriverController extends Controller
{
    public function getDailyRoute(Request $request)
    {
        $user = $request->user();
        $log = DriverLog::where('driver_id', $user->id)
            ->whereNull('ended_at')
            ->with(['saccoRoute.route', 'vehicle'])
            ->latest('started_at')
            ->first();

        return response()->json($log);
    }

    public function setDailyRoute(Request $request)
    {
        $validated = $request->validate([
            'sacco_route_id' => 'required|exists:sacco_routes,sacco_route_id',
        ]);

        $user = $request->user();

        // End current active log if exists
        DriverLog::where('driver_id', $user->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);

        // Find driver's vehicle (assuming one active vehicle per driver)
        $vehicle = Vehicle::where('driver_id', $user->id)->first();

        $log = DriverLog::create([
            'driver_id'      => $user->id,
            'vehicle_id'     => $vehicle?->id,
            'sacco_route_id' => $validated['sacco_route_id'],
            'started_at'     => now(),
        ]);

        return response()->json($log->load(['saccoRoute.route', 'vehicle']));
    }

    public function toggleShift(Request $request)
    {
        $user = $request->user();
        $activeLog = DriverLog::where('driver_id', $user->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        if ($activeLog) {
            // End all active shifts
            DriverLog::where('driver_id', $user->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);
            return response()->json(['status' => 'ended', 'log' => $activeLog]);
        } else {
            return response()->json(['status' => 'inactive', 'message' => 'Select a route to start shift']);
        }
    }

    public function getAvailableRoutes(Request $request)
    {
        $user = $request->user();
        $vehicle = Vehicle::where('driver_id', $user->id)->first();

        if (!$vehicle) {
            return response()->json(['message' => 'No vehicle assigned'], 404);
        }

        $routes = SaccoRoute::where('sacco_id', $vehicle->sacco_id)
            ->with('route')
            ->get();

        // Transform: detect direction from sacco_route_id suffix.
        // Convention: _001 = outbound (A→B), _002 = return direction (B→A).
        // Both share the same routes record, so we swap start/end labels for _002
        // so drivers can tell the two directions apart.
        $mapped = $routes->map(function ($saccoRoute) {
            $route = $saccoRoute->route;
            if (!$route) return null;

            // Extract direction suffix (last segment after final underscore)
            $parts = explode('_', $saccoRoute->sacco_route_id);
            $directionSuffix = end($parts); // "001" or "002"
            $isReturn = ($directionSuffix === '002');

            return [
                'sacco_route_id' => $saccoRoute->sacco_route_id,
                'route' => [
                    'route_id'         => $route->route_id,
                    'route_number'     => $route->route_number,
                    'route_start_stop' => $isReturn ? $route->route_end_stop   : $route->route_start_stop,
                    'route_end_stop'   => $isReturn ? $route->route_start_stop : $route->route_end_stop,
                ],
            ];
        })->filter()->values();

        return response()->json($mapped);
    }
}
