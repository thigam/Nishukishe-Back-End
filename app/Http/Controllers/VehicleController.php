<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\DriverLocation;
use App\Services\VehicleTracking\VehicleTrackingService;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    protected $trackingService;

    public function __construct(VehicleTrackingService $trackingService)
    {
        $this->trackingService = $trackingService;
    }

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

        if (!$location) {
            return response()->json(['message' => 'Location not found'], 404);
        }

        return response()->json($location);
    }

    public function getSaccoFleet(Request $request)
    {
        $user = $request->user();
        if (!$user->sacco_id) {
            return response()->json(['message' => 'Sacco ID not found for user'], 403);
        }

        $fleet = $this->trackingService->getFleetForSacco($user->sacco_id);

        return response()->json($fleet);
    }

    public function getOwnerFleet(Request $request)
    {
        $user = $request->user();
        if ($user->role !== \App\Models\UserRole::VEHICLE_OWNER) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $fleet = $this->trackingService->getFleetForOwner($user->id);

        return response()->json($fleet);
    }
}
