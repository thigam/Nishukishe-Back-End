<?php

namespace App\Http\Controllers;

use App\Models\DriverLocation;
use App\Models\UserRole;
use App\Services\VehicleTracking\VehicleTrackingService;
use App\Services\VehicleTracking\IncidentService;
use Illuminate\Http\Request;

class DriverLocationController extends Controller
{
    protected $trackingService;
    protected $incidentService;

    public function __construct(VehicleTrackingService $trackingService, IncidentService $incidentService)
    {
        $this->trackingService = $trackingService;
        $this->incidentService = $incidentService;
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== UserRole::DRIVER) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $data['driver_id'] = $user->id;
        $data['recorded_at'] = $data['recorded_at'] ?? now();

        $location = $this->trackingService->trackPing($user->id, $data);

        return response()->json($location, 201);
    }

    public function showLatest(Request $request)
    {
        $validated = $request->validate([
            'driver_id' => ['nullable', 'integer'],
            'vehicle_id' => ['nullable', 'integer'],
        ]);

        $query = DriverLocation::query();

        if (!empty($validated['driver_id'])) {
            $query->where('driver_id', $validated['driver_id']);
        }

        if (!empty($validated['vehicle_id'])) {
            $query->where('vehicle_id', $validated['vehicle_id']);
        }

        $location = $query->orderByDesc('recorded_at')->first();

        if (!$location) {
            return response()->json(['message' => 'Location not found'], 404);
        }

        return response()->json($location);
    }

    public function reportIncident(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'type' => 'required|in:accident,traffic,delay',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'description' => 'nullable|string',
        ]);

        $incident = $this->incidentService->report($user->id, $validated['vehicle_id'], $validated);

        return response()->json($incident, 201);
    }
}
