<?php

namespace App\Http\Controllers;

use App\Models\PreCleanSaccoRoute;
use App\Models\PreCleanVariation;
use App\Models\PostCleanVariation;
use App\Models\PostCleanSaccoRoute;
use Illuminate\Http\Request;

class PreCleanVariationController extends Controller
{
    public function index($saccoRouteId)
    {
        if (is_numeric($saccoRouteId)) {
            $route = \App\Models\PreCleanSaccoRoute::find($saccoRouteId);
            if ($route) {
                return PreCleanVariation::where('sacco_route_id', $route->sacco_route_id)->get();
            }
        }
        return PreCleanVariation::where('sacco_route_id', $saccoRouteId)->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sacco_route_id' => 'required|string|exists:pre_clean_sacco_routes,sacco_route_id',
            'coordinates' => 'array',
            'stop_ids' => 'array',
        ]);

        $variation = PreCleanVariation::create($data);
        return response()->json($variation, 201);
    }

    public function approve($id)
    {
        $pre = PreCleanVariation::findOrFail($id);
        $pre->status = 'cleaned';
        $pre->save();

        $route = PostCleanSaccoRoute::where('sacco_route_id', $pre->sacco_route_id)->first();

        if (!$route) {
            $preCleanRouteId = $pre->saccoRoute?->id
                ?? PreCleanSaccoRoute::where('sacco_route_id', $pre->sacco_route_id)->value('id');

            if ($preCleanRouteId) {
                $route = PostCleanSaccoRoute::where('pre_clean_id', $preCleanRouteId)->first();
            }
        }

        if (!$route) {
            return response()->json(['message' => 'Post clean route not found'], 404);
        }

        $post = PostCleanVariation::create([
            'pre_clean_id' => $pre->id,
            'sacco_route_id' => $route->sacco_route_id,
            'coordinates' => $pre->coordinates,
            'stop_ids' => $pre->stop_ids,
        ]);

        return response()->json($post);
    }

    public function reject($id)
    {
        $pre = PreCleanVariation::findOrFail($id);
        $pre->status = 'rejected';
        $pre->save();

        return response()->json($pre);
    }
}
