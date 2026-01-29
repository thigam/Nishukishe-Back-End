<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class StopTimesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        //return all stop times
        $stopTimes = StopTimes::all();
        return response()->json($stopTimes);
    }

    public function showByStop($stop): JsonResponse
    {
        //return a specific stop time
        $stopTime = StopTimes::where('stop', $stop)->first();
        if ($stopTime) {
            return response()->json($stopTime);
        }
        return response()->json(['message' => 'Stop time not found'], 404);
    }

    public function showByRoute($route): JsonResponse
    {
        //return all stop times for a specific route
        $stopTimes = StopTimes::where('route', $route)->get();
        if ($stopTimes->isNotEmpty()) {
            return response()->json($stopTimes);
        }
        return response()->json(['message' => 'No stop times found for this route'], 404);
    }
    public function showBySacco($sacco): JsonResponse
    {
        //return all stop times for a specific sacco
        $stopTimes = StopTimes::where('sacco', $sacco)->get();
        if ($stopTimes->isNotEmpty()) {
            return response()->json($stopTimes);
        }
        return response()->json(['message' => 'No stop times found for this sacco'], 404);
    }
    public function showByDirection($direction): JsonResponse
    {
        //return all stop times for a specific direction
        $stopTimes = StopTimes::where('direction', $direction)->get();
        if ($stopTimes->isNotEmpty()) {
            return response()->json($stopTimes);
        }
        return response()->json(['message' => 'No stop times found for this direction'], 404);
    }
}
