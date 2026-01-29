<?php

namespace App\Http\Controllers;

use App\Models\PostCleanVariation;
use App\Models\PostCleanSaccoRoute;
use App\Models\Variation;
use Illuminate\Http\Request;

class PostCleanVariationController extends Controller
{
    public function index($saccoRouteId)
    {
        return PostCleanVariation::where('sacco_route_id', $saccoRouteId)->get();
    }

    public function show($id)
    {
        $variation = PostCleanVariation::findOrFail($id);
        return response()->json($variation);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'pre_clean_id' => 'required|integer|exists:pre_clean_variations,id',
            'sacco_route_id' => 'required|string|exists:post_clean_sacco_routes,sacco_route_id',
            'coordinates' => 'array',
            'stop_ids' => 'array',
        ]);

        $variation = PostCleanVariation::create($data);
        return response()->json($variation, 201);
    }

    public function approve($id)
    {
        $post = PostCleanVariation::findOrFail($id);
        $post->status = 'cleaned';
        $post->save();

        $route = PostCleanSaccoRoute::where('sacco_route_id', $post->sacco_route_id)->first();
        if (!$route) {
            return response()->json(['message' => 'Route not found'], 404);
        }

        $variation = Variation::create([
            'sacco_route_id' => $route->sacco_route_id,
            'coordinates' => $post->coordinates,
            'stop_ids' => $post->stop_ids,
        ]);

        return response()->json($variation);
    }

    public function reject($id)
    {
        $post = PostCleanVariation::findOrFail($id);
        $post->status = 'rejected';
        $post->save();

        return response()->json($post);
    }
}
