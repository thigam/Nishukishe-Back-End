<?php

namespace App\Http\Controllers;

use App\Models\PostCleanStop;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class PostCleanStopController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'pre_clean_id'     => 'required|integer',
            'stop_id'          => 'required|string',
            'sacco_route_ids'  => [
                'required',
                'array',
                'min:1',
                Rule::forEach(fn ($value) => ['string', Rule::exists('post_clean_sacco_routes', 'sacco_route_id')]),
            ],
            'stop_name'        => 'required|string',
            'stop_lat'         => 'required|numeric',
            'stop_long'        => 'required|numeric',
            'county_id'        => 'nullable|integer',
            'direction_id'     => 'nullable|integer',
        ]);

        $attributes = Arr::except($data, ['sacco_route_ids']);
        $routeIds   = $data['sacco_route_ids'];

        $stop = PostCleanStop::where('stop_id', $attributes['stop_id'])->first();

        if ($stop) {
            $stop->fill($attributes);
            $stop->syncSaccoRouteIds(array_merge($stop->sacco_route_ids ?? [], $routeIds), false);
            $stop->save();

            return response()->json($stop->fresh());
        }

        $stop = new PostCleanStop($attributes);
        $stop->syncSaccoRouteIds($routeIds, false);
        $stop->save();

        return response()->json($stop->fresh(), 201);
    }
}
