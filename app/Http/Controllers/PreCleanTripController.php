<?php

// app/Http/Controllers/PreCleanTripController.php
namespace App\Http\Controllers;

use App\Models\PreCleanTrip;
use Illuminate\Http\Request;

class PreCleanTripController extends Controller
{
    public function store(Request $request)
    {

        $data = $request->validate([
            'sacco_route_id' => 'required|string|exists:pre_clean_sacco_routes,sacco_route_id',
            'stop_times' => 'required|array',
            'stop_times.*.stop_id' => 'required|integer',
            'stop_times.*.time' => 'required|date_format:H:i',
            'day_of_week' => 'nullable|array',
            'day_of_week.*' => 'in:mon,tue,wed,thu,fri,sat,sun',
        ]);
        $trip = PreCleanTrip::create($data);

        return response()->json($trip, 201);
    }

    public function index($routeId)
    {
        // If routeId is numeric, it's likely the PK (id).
        // If it's a string, it might be the sacco_route_id itself.
        // We try to find the route by ID first to get the correct string key.
        if (is_numeric($routeId)) {
            $route = \App\Models\PreCleanSaccoRoute::find($routeId);
            if ($route) {
                return PreCleanTrip::where('sacco_route_id', $route->sacco_route_id)->get();
            }
        }

        // Fallback: try querying directly (in case routeId IS the string key)
        return PreCleanTrip::where('sacco_route_id', $routeId)->get();
    }
}

