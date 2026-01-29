<?php

namespace App\Http\Controllers;
use App\Models\Stops;
use Illuminate\Http\JsonResponse;

use Illuminate\Http\Request;

class StopsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        //return all stop names
        // $stopNames = Stops::pluck('stop_name');
        $stopNames = Stops::all();
        return response()->json($stopNames);
    }

    public function showByStop($stop): JsonResponse
    {
        //return a specific stop
        $stop = Stops::where('stop', $stop)->first();
        if ($stop) {
            return response()->json($stop);
        }
        return response()->json(['message' => 'Stop not found'], 404);
    }

    public function showByLetters($letters): JsonResponse
    {
        // Return all stop names starting with the given set of letters
        $stops = Stops::where('stop_name', 'like', $letters . '%')->pluck('stop_name');
        if ($stops->isNotEmpty()) {
            return response()->json($stops);
        }
        return response()->json(['message' => 'No stop names found starting with these letters'], 404);
    }

    public function ShowByRoute($route): JsonResponse
    {
        //return all stops for a specific route
        $stops = Stops::where('route', $route)->get();
        if ($stops->isNotEmpty()) {
            return response()->json($stops);
        }
        return response()->json(['message' => 'No stops found for this route'], 404);
    }
    public function nearby(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat'    => 'required|numeric',
            'lng'    => 'required|numeric',
            'radius' => 'numeric',
        ]);
        $radius = $data['radius'] ?? 1; // kilometers

        $expr = '(6371 * acos(cos(radians(?)) * cos(radians(stop_lat)) * cos(radians(stop_long) - radians(?)) + sin(radians(?)) * sin(radians(stop_lat))))';

        $stops = Stops::select('stop_id','stop_name','stop_lat','stop_long')
            ->selectRaw("{$expr} AS distance", [$data['lat'], $data['lng'], $data['lat']])
            ->having('distance', '<=', $radius)
            ->orderBy('distance')
            ->get();

        return response()->json($stops);
    }
}
