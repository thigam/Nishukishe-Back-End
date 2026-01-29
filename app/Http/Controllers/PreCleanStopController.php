<?php

namespace App\Http\Controllers;

use App\Models\PreCleanStop;
use App\Models\PostCleanStop;
use App\Services\StopIdGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class PreCleanStopController extends Controller
{
    public function index()
    {
        return response()->json(PreCleanStop::all());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id'               => ['sometimes', 'numeric'],
            'sacco_route_ids'   => ['required', 'array', 'min:1'],
            'sacco_route_ids.*' => ['string', Rule::exists('pre_clean_sacco_routes', 'sacco_route_id')],
            'stop_name'         => 'required|string',
            'stop_lat'          => 'required|numeric',
            'stop_long'         => 'required|numeric',
            'county_id'         => 'nullable',
            'direction_id'      => 'nullable',
            'status'            => 'sometimes|string',
        ]);

        $attributes = Arr::except($data, ['sacco_route_ids', 'id']);
        $providedId = $data['id'] ?? null;

        if ($providedId !== null) {
            $stop = PreCleanStop::find($providedId) ?? new PreCleanStop();
            if (! $stop->exists) {
                $stop->id = $providedId;
            }
        } else {
            $stop = new PreCleanStop();
        }

        if (! empty($attributes)) {
            $stop->fill($attributes);
        }

        $stop->syncSaccoRouteIds($data['sacco_route_ids'], false);
        $stop->save();

        $status = $stop->wasRecentlyCreated ? 201 : 200;

        return response()->json($stop->fresh(), $status);
    }

    public function show(string $id)
    {
        return response()->json(PreCleanStop::findOrFail($id));
    }

    public function update(Request $request, string $id)
    {
        $stop = PreCleanStop::findOrFail($id);

        $data = $request->validate([
            'sacco_route_ids'   => ['required', 'array', 'min:1'],
            'sacco_route_ids.*' => ['string', Rule::exists('pre_clean_sacco_routes', 'sacco_route_id')],
            'stop_name'         => 'required|string',
            'stop_lat'          => 'required|numeric',
            'stop_long'         => 'required|numeric',
            'county_id'         => 'nullable',
            'direction_id'      => 'nullable',
            'status'            => 'sometimes|string',
        ]);

        $routeIds = $data['sacco_route_ids'] ?? null;
        unset($data['sacco_route_ids']);

        if (! empty($data)) {
            $stop->fill($data);
        }

        if ($routeIds !== null) {
            $stop->syncSaccoRouteIds($routeIds, false);
        }

        $stop->save();

        return response()->json($stop->fresh());
    }

    public function destroy(Request $request, string $id)
    {
        $stop = PreCleanStop::findOrFail($id);
        $routeId = $request->input('sacco_route_id');

        if ($routeId !== null) {
            $changed = $stop->detachSaccoRouteId(trim((string) $routeId), false);

            if ($changed) {
                if (empty($stop->sacco_route_ids)) {
                    $stop->delete();
                } else {
                    $stop->save();
                }

                return response()->json(['message' => 'detached']);
            }
        }

        $stop->delete();

        return response()->json(['message' => 'deleted']);
    }

    public function approve(string $id)
    {
        $pre = PreCleanStop::findOrFail($id);
        $pre->status = 'cleaned';
        $pre->save();

        /** @var StopIdGenerator $generator */
        $generator = app(StopIdGenerator::class);
        $formattedId = $generator->generate((float) $pre->stop_lat, (float) $pre->stop_long);

        $post = PostCleanStop::firstOrNew(['stop_id' => $formattedId]);

        $post->fill([
            'pre_clean_id' => $pre->id,
            'stop_id'      => $formattedId,
            'stop_name'    => $pre->stop_name,
            'stop_lat'     => $pre->stop_lat,
            'stop_long'    => $pre->stop_long,
            'county_id'    => $pre->county_id,
            'direction_id' => $pre->direction_id,
        ]);

        $mergedRouteIds = array_merge($post->sacco_route_ids ?? [], $pre->sacco_route_ids ?? []);
        $post->syncSaccoRouteIds($mergedRouteIds, false);
        $post->save();

        return response()->json($post->fresh());
    }

    public function reject(string $id)
    {
        $pre = PreCleanStop::findOrFail($id);
        $pre->status = 'rejected';
        $pre->save();
        return response()->json($pre);
    }
}
