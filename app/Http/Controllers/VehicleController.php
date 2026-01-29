<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\DriverLocation;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $vehicles = Vehicle::with(['driver', 'route'])
            ->where('owner_id', $user->id)
            ->get();

        return response()->json($vehicles);
    }

    public function assignDriver(Request $request, Vehicle $vehicle)
    {
        $user = $request->user();
        if ($vehicle->owner_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'driver_id' => ['nullable', 'exists:users,id'],
        ]);

        $vehicle->driver_id = $data['driver_id'];
        $vehicle->save();

        return response()->json($vehicle);
    }

    public function location(Request $request, Vehicle $vehicle)
    {
        $user = $request->user();
        if ($vehicle->owner_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $location = DriverLocation::where('vehicle_id', $vehicle->id)
            ->orderByDesc('recorded_at')
            ->first();

        if (! $location) {
            return response()->json(['message' => 'Location not found'], 404);
        }

        return response()->json($location);
    }
}
