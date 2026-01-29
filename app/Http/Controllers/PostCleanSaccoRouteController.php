<?php

namespace App\Http\Controllers;

use App\Services\SaccoRoutePublishLogger;
use Illuminate\Http\Request;
use App\Models\PostCleanSaccoRoute;

class PostCleanSaccoRouteController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'pre_clean_id' => 'required|integer',
            'route_id' => 'required|string',  // base id
            'sacco_route_id' => 'required|string',  // composite id
            'sacco_id' => 'required|string',
            'route_start_stop' => 'nullable|string',
            'route_end_stop' => 'nullable|string',
            'coordinates' => 'nullable|array',
            'stop_ids' => 'nullable|array',
            'peak_fare' => 'nullable|numeric',
            'off_peak_fare' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'county_id' => 'nullable|integer',
            'mode' => 'nullable|string',
            'waiting_time' => 'nullable|numeric',
            'direction_index' => 'nullable|integer',
        ]);

        $data['direction_index'] = $data['direction_index'] ?? 1;
        $data['currency'] = strtoupper($request->input('currency', 'KES'));

        $route = PostCleanSaccoRoute::create($data);
        app(SaccoRoutePublishLogger::class)->log($route->sacco_route_id, $request->user());
        return response()->json($route, 201);
    }

    public function index()
    {
        return response()->json(PostCleanSaccoRoute::all());
    }

    public function show($id)
    {
        return response()->json(PostCleanSaccoRoute::findOrFail($id));
    }

    public function destroy(Request $request, $id)
    {
        $request->validate(['password' => 'required|string']);
        if (!\Illuminate\Support\Facades\Hash::check($request->password, $request->user()->password)) {
            return response()->json(['message' => 'Invalid password'], 401);
        }

        PostCleanSaccoRoute::findOrFail($id)->delete();
        return response()->json(['message' => 'deleted']);
    }
}

