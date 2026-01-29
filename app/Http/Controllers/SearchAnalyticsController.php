<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\SearchLog;

class SearchAnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $query = SearchLog::query()->latest();

        // Filter by Status (Success/Fail)
        if ($request->has('status')) {
            $status = $request->input('status');
            if ($status === 'success') {
                $query->where('has_result', true);
            } elseif ($status === 'fail') {
                $query->where('has_result', false);
            }
        }

        // Filter by Origin Station
        if ($request->filled('origin')) {
            $query->where('origin_slug', $request->input('origin'));
        }

        // Filter by Destination Station
        if ($request->filled('destination')) {
            $query->where('destination_slug', $request->input('destination'));
        }

        // Filter by Radius
        if ($request->filled(['lat', 'lng', 'radius'])) {
            $lat = $request->input('lat');
            $lng = $request->input('lng');
            $radius = $request->input('radius'); // in km
            $type = $request->input('filter_type', 'origin'); // origin, destination, or both

            $haversine = "(6371 * acos(cos(radians($lat)) * cos(radians(%s)) * cos(radians(%s) - radians($lng)) + sin(radians($lat)) * sin(radians(%s))))";

            $query->where(function ($q) use ($haversine, $radius, $type) {
                if ($type === 'origin' || $type === 'both') {
                    $q->orWhereRaw(sprintf($haversine, 'origin_lat', 'origin_lng', 'origin_lat') . " < ?", [$radius]);
                }
                if ($type === 'destination' || $type === 'both') {
                    $q->orWhereRaw(sprintf($haversine, 'destination_lat', 'destination_lng', 'destination_lat') . " < ?", [$radius]);
                }
            });
        }

        $logs = $query->paginate(20);

        return response()->json($logs);
    }
}
