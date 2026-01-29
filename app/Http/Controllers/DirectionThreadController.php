<?php

namespace App\Http\Controllers;

use App\Models\DirectionThread;
use App\Models\Stops;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class DirectionThreadController extends Controller
{
    public function index(): JsonResponse
    {
        // Fetch all threads
        // We need coordinates. Since we don't store them on the thread, we have to look them up.
        // This might be N+1 if we are not careful.
        // Optimization: We can cache this or just do a heavy query.
        // For now, let's try to join with stops if possible, but slugs are not foreign keys.
        // We will do a best-effort lookup or just return 0,0 if not found (frontend might break).

        // Actually, for the "search" page, we just need the list.
        // But the frontend type `RouteGuide` enforces coords.

        // Fetch threads with stop details
        // Optimization: Eager load stops if we define the relationship, but for now manual lookup is fine for small batches.
        // Actually, let's just grab all stops we need.

        $threads = DirectionThread::all();

        // Collect all stop IDs
        $stopIds = $threads->pluck('origin_stop_id')->merge($threads->pluck('destination_stop_id'))->filter()->unique();
        $stops = Stops::whereIn('stop_id', $stopIds)->get()->keyBy('stop_id');

        $guides = $threads->map(function ($thread) use ($stops) {
            $originName = ucwords(str_replace('-', ' ', $thread->origin_slug));
            $destName = ucwords(str_replace('-', ' ', $thread->destination_slug));

            $originStop = $stops->get($thread->origin_stop_id);
            $destStop = $stops->get($thread->destination_stop_id);

            $originCoords = $originStop ? [(float) $originStop->stop_lat, (float) $originStop->stop_long] : [0, 0];
            $destCoords = $destStop ? [(float) $destStop->stop_lat, (float) $destStop->stop_long] : [0, 0];

            // Generate generic text
            $summary = "Travel from {$originName} to {$destName}. We found multiple travel options for you.";
            $intro = "Planning a trip from {$originName} to {$destName}? Check out our curated guide for the best routes, fares, and travel tips.";

            return [
                'origin' => $originName,
                'destination' => $destName,
                'originSlug' => $thread->origin_slug,
                'destinationSlug' => $thread->destination_slug,
                'originCoords' => $originCoords,
                'destinationCoords' => $destCoords,
                'summary' => $summary,
                'intro' => $intro,
                'travelOptions' => [
                    [
                        'name' => 'Public Transport',
                        'description' => 'Reliable matatu and bus services available.',
                        'fares' => ['Check current rates'],
                        'duration' => 'Varies by traffic'
                    ]
                ],
                'travelTips' => ['Travel early to avoid traffic.', 'Keep your valuables safe.'],
                'amenities' => [],
                'mapQuery' => "{$originName} to {$destName}"
            ];
        });

        return response()->json(['guides' => $guides]);
    }
}
