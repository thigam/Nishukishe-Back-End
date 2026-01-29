<?php

namespace App\Http\Controllers;

use App\Models\DriverLocation;
use App\Models\UserRole;
use Illuminate\Http\Request;

class DriverLocationController extends Controller
{
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

        $location = DriverLocation::create($data);

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
}
