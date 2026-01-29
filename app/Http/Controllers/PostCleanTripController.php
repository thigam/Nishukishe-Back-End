<?php

namespace App\Http\Controllers;

use App\Models\PostCleanTrip;
use Illuminate\Http\Request;

class PostCleanTripController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'pre_clean_id'   => 'required|integer',
            'route_id'       => 'required|string',
            'sacco_id'       => 'required|string',
            'sacco_route_id' => 'required|string|exists:post_clean_sacco_routes,sacco_route_id',
            'trip_times'     => 'required|array',
            'day_of_week'    => 'nullable|array',
            'day_of_week.*'  => 'in:mon,tue,wed,thu,fri,sat,sun',
        ]);

        $data['day_of_week'] = $data['day_of_week'] ?? [];

        $trip = PostCleanTrip::create($data);

        return response()->json($trip, 201);
    }

    public function show($id)
    {
        $trip = PostCleanTrip::findOrFail($id);

        return response()->json($trip);
    }
}
