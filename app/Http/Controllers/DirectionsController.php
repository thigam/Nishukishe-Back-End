<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Directions;
use Illuminate\Http\JsonResponse;
use App\Models\SaccoRoutes;
use App\Models\Stops;
use Illuminate\Support\Facades\Log;

class DirectionsController extends Controller
{
    public function index(Request $request):JsonResponse
    {
        //return all directions
        $directions=Directions::all();
        return response()->json($directions);
    }
    public function show($direction_heading):JsonResponse
    {
        //return a specific direction
        $direction=Directions::where('heading',$direction_heading)->first();
        if($direction){
            return response()->json($direction);
        }
        return response()->json(['message'=>'Direction not found'],404);
    }

    public function showRoutes($route):JsonResponse
    {
        //return all directions for a specific route
        $directions=Directions::where('route',$route)->get();
        if($directions->isNotEmpty()){
            return response()->json($directions);
        }
        return response()->json(['message'=>'No directions found for this route'],404);
    }

    public function showByEnding($ending):JsonResponse
    {
        //return all directions ending with a specific location
        $directions=Directions::where('ending_location',$ending)->get();
        if($directions->isNotEmpty()){
            return response()->json($directions);
        }
        return response()->json(['message'=>'No directions found for this ending location'],404);
    }

    /*
    * Takes a start and end location 
    * searches the direction table  for routes that match the start and end
    * returns the stop_id, stop_name, latitude, longitude of the stops in between
    */

    public function searchByStartEnd(Request $request):JsonResponse
    {
        $start=$request->query('start');
        $end=$request->query('end');
        if(!$start || !$end){
            return response()->json(['message'=>'Please provide both start and end locations'],400);
        }
        //search for directions that match the start and end
        $directions=Directions::where('starting_location','LIKE',"%$start%")
            ->where('ending_location','LIKE',"%$end%")
            ->get();
        if($directions->isNotEmpty()){
            return response()->json($directions);
        }
        return response()->json(['message'=>'No directions found for this start and end location'],404);
    }
    //turn the above to a get request
    public function searchByStartEndGet($end_lat,$end_long):JsonResponse
    {
        if (!$end_lat || !$end_long) {
            return response()->json(['message' => 'Please provide both start and end locations'], 400);
        }

        // radii in meters (100m, 400m, 800m, 1200m)
        $radii = [100, 400, 800, 1200];

        // approximate conversion: 1° latitude ≈ 111,320m
        $metersPerDegree = 111320;
        $directions = collect();

        foreach ($radii as $radius) {
            $deg = $radius / $metersPerDegree;

            $directions = Directions::whereBetween('direction_latitude', [$end_lat - $deg, $end_lat + $deg])
                ->whereBetween('direction_longitude', [$end_long - $deg, $end_long + $deg])
                ->get();

            if ($directions->isNotEmpty()) {
                break; // stop widening once we find results
            }
        }

        if ($directions->isEmpty()) {
            return response()->json(['message' => 'No directions found for this start and end location'], 404);
        }

        // Collect routes from directions
        $routes = $directions->pluck('direction_routes')->flatten()->unique();

        // Get all stop_ids from sacco routes
        $stop_ids = SaccoRoutes::whereIn('sacco_route_id', $routes)
            ->pluck('stop_ids')
            ->flatten()
            ->unique()
            ->values();

        // Fetch all stop details
        $stops = Stops::whereIn('stop_id', $stop_ids)->get();

        return response()->json($stops);
    }
    public function findStops(Request $request)
    {
        $request->validate([
            'end_lat' => 'required|numeric',
            'end_long' => 'required|numeric',
        ]);

        $end_lat = $request->input('end_lat');
        $end_long = $request->input('end_long');

        // radii in meters (100m, 400m, 800m, 1200m)
        $radii = [100, 400, 800, 1200];
        $metersPerDegree = 111320; // ~1 degree latitude ≈ 111.32 km

        $directions = collect();
        $used_radius = null;

        foreach ($radii as $radius) {
            $deg = $radius / $metersPerDegree;

            $directions = Directions::whereBetween('direction_latitude', [$end_lat - $deg, $end_lat + $deg])
                ->whereBetween('direction_longitude', [$end_long - $deg, $end_long + $deg])
                ->get();

            if ($directions->isNotEmpty()) {
                $used_radius = $radius;
                break; // stop widening once we find results
            }
        }

        if ($directions->isEmpty()) {
            return response()->json(['message' => 'No directions found for this location'], 404);
        }

        // Collect routes from directions
        $routes = $directions->pluck('direction_routes')->flatten()->unique();

        // Get all stop_ids from sacco routes
        $stop_ids = SaccoRoutes::whereIn('sacco_route_id', $routes)
            ->pluck('stop_ids')
            ->flatten()
            ->unique()
            ->values();

        // Fetch all stop details
        $stops = Stops::whereIn('stop_id', $stop_ids)->get();

        return response()->json([
            'search_radius_meters' => $used_radius,
            'directions_found' => $directions->count(),
            'stops' => $stops,
        ]);
    }

}