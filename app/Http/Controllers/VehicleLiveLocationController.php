<?php

namespace App\Http\Controllers;

use App\Services\VehicleTracking\VehicleTrackingService;
use Illuminate\Http\Request;

class VehicleLiveLocationController extends Controller
{
    protected $trackingService;

    public function __construct(VehicleTrackingService $trackingService)
    {
        $this->trackingService = $trackingService;
    }

    /**
     * Get nearby vehicles for a specific sacco route.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'sacco_route_id' => 'required|string|exists:sacco_routes,sacco_route_id',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
        ]);

        $vehicles = $this->trackingService->getNearbyVehicles(
            $validated['sacco_route_id'],
            $validated['lat'] ?? 0,
            $validated['lng'] ?? 0
        );

        return response()->json($vehicles);
    }
}
