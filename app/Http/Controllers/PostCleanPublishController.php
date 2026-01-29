<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\Route as MainRoute;
use App\Models\SaccoRoutes;
use App\Models\Sacco;
use App\Models\Stops;
use App\Models\Trip;
use App\Models\Variation;
use App\Services\SaccoRoutePublishLogger;

use App\Models\PostCleanSaccoRoute;
use App\Models\PostCleanStop;
use App\Models\PostCleanTrip;
use App\Models\PostCleanVariation;
use Illuminate\Support\Facades\Log;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class PostCleanPublishController extends Controller
{
    /**
     * Promote all post-clean data into the main tables.
     * - Creates/updates Route & SaccoRoutes rows
     * - Upserts Stops that don't already exist
     * - Copies Trips and Variations
     *
     * NOTE about IDs:
     *   PostCleanSaccoRoute::sacco_route_id contains the composite key
     *   (e.g., SACCO123_R001_001) while PostCleanSaccoRoute::route_id keeps
     *   the base route identifier. Older rows may still have the composite
     *   stored in route_id, so we normalise both values when publishing.
     */
    public function publishAll(Request $request)
    {
        $routes = PostCleanSaccoRoute::all();
        return $this->publishRoutes($routes);
    }

    public function publishSelected(Request $request)
    {
        $postCleanIds = $request->input('post_clean_ids', []);
        $saccoRouteIds = $request->input('sacco_route_ids', []);

        if (empty($postCleanIds) && empty($saccoRouteIds)) {
            abort(422, 'No route identifiers provided.');
        }

        $routes = PostCleanSaccoRoute::query()
            ->when($postCleanIds, fn($q) => $q->whereIn('id', $postCleanIds))
            ->when($saccoRouteIds, function ($q) use ($saccoRouteIds) {
                $q->orWhereIn('sacco_route_id', $saccoRouteIds)
                    ->orWhereIn('route_id', $saccoRouteIds);
            })
            ->get();

        return $this->publishRoutes($routes);
    }

    protected function publishRoutes($routes)
    {
        $published = [];
        $failed = [];

        foreach ($routes as $pc) {
            try {
                DB::transaction(function () use ($pc, &$published) {
                    // 1) derive base + composite identifiers in a backward compatible way
                    $saccoRouteId = $pc->sacco_route_id;
                    $baseRouteId = $pc->route_id;

                    if (!$saccoRouteId && is_string($pc->route_id) && preg_match('/^[A-Z0-9]+_\d+_\d{3}$/', $pc->route_id)) {
                        $parts = explode('_', $pc->route_id);
                        $baseRouteId = $parts[1] ?? $pc->route_id;
                        $saccoRouteId = $pc->route_id;
                    }

                    if (!$saccoRouteId) {
                        abort(422, 'Missing sacco_route_id for post-clean route ' . $pc->id);
                    }

                    // 2) route must already exist in main db (per your latest design)
                    $baseRoute = MainRoute::find($baseRouteId);
                    if (!$baseRoute) {
                        abort(422, "Base route {$baseRouteId} not found. Create it first.");
                    }

                    // 3) upsert only the stops actually used on this route
                    $pcStops = PostCleanStop::forRoute($saccoRouteId)->get();
                    foreach ($pcStops as $s) {
                        if (Stops::where('stop_id', (string) $s->stop_id)->exists()) {
                            continue;
                        }
                        Stops::create([
                            'stop_id' => (string) $s->stop_id,
                            'stop_name' => $s->stop_name,
                            'stop_lat' => (float) $s->stop_lat,  // model uses stop_lan
                            'stop_long' => (float) $s->stop_long,
                            'county_id' => $s->county_id,
                            //      'direction_id'=> $s->direction_id,
                        ]);
                    }

                    // 4) upsert sacco_route row in main table
                    SaccoRoutes::updateOrCreate(
                        ['sacco_route_id' => $saccoRouteId],
                        [
                            'route_id' => $baseRouteId,
                            'sacco_id' => $pc->sacco_id,
                            'stop_ids' => $pc->stop_ids ?? [],
                            'coordinates' => $pc->coordinates ?? [],
                            'peak_fare' => (float) ($pc->peak_fare ?? 0),
                            'off_peak_fare' => (float) ($pc->off_peak_fare ?? 0),
                            'currency' => $pc->currency ?? 'KES',
                            'scheduled' => true,
                            'has_variations' => PostCleanVariation::where('sacco_route_id', $saccoRouteId)->exists(),
                        ]
                    );

                    // 5) trips: dedupe on (sacco_route_id, stop_times, day_of_week) + index
                    $pcTrips = PostCleanTrip::where('sacco_route_id', $saccoRouteId)
                        ->where('sacco_id', $pc->sacco_id)
                        ->get();
                    $existing = Trip::where('sacco_route_id', $saccoRouteId)->get();

                    $eq = fn($a, $b) => json_encode($a) === json_encode($b);

                    $nextIndex = function ($existing) {
                        $max = 0;
                        foreach ($existing as $t) {
                            $n = (int) ($t->trip_index ?? 0);
                            if ($n > $max)
                                $max = $n;
                        }
                        return str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
                    };

                    foreach ($pcTrips as $t) {
                        $dup = $existing->first(function ($et) use ($t, $eq) {
                            return $eq($et->stop_times, $t->trip_times)
                                && $eq($et->day_of_week, $t->day_of_week ?? null);
                        });
                        if ($dup)
                            continue;

                        $index = $nextIndex($existing);

                        $startTime = null;
                        if (!empty($t->trip_times) && is_array($t->trip_times)) {
                            // Assuming sorted by sequence, or just take the first one
                            $first = $t->trip_times[0] ?? null;
                            if ($first && isset($first['time'])) {
                                $startTime = $first['time'];
                            }
                        }

                        $created = Trip::create([
                            'sacco_id' => $pc->sacco_id,
                            'route_id' => $baseRouteId,
                            'sacco_route_id' => $saccoRouteId,
                            'trip_index' => $index,
                            'stop_times' => $t->trip_times,
                            'day_of_week' => $t->day_of_week ?? [],
                            'start_time' => $startTime,
                        ]);

                        $existing->push($created);
                    }

                    // 6) variations
                    $pcVars = PostCleanVariation::where('sacco_route_id', $saccoRouteId)->get();
                    foreach ($pcVars as $v) {
                        Variation::create([
                            'sacco_route_id' => $saccoRouteId,
                            'coordinates' => $v->coordinates ?? [],
                            'stop_ids' => $v->stop_ids ?? [],
                        ]);
                    }

                    app(SaccoRoutePublishLogger::class)->log($saccoRouteId, auth()->user());

                    // 7) cleanup for THIS route only (after successful inserts)
                    PostCleanTrip::where('sacco_route_id', $saccoRouteId)->delete();
                    PostCleanVariation::where('sacco_route_id', $saccoRouteId)->delete();
                    PostCleanStop::forRoute($saccoRouteId)->get()->each(function (PostCleanStop $stop) use ($saccoRouteId) {
                        $changed = $stop->detachSaccoRouteId($saccoRouteId, false);

                        if (!$changed) {
                            return;
                        }

                        if (empty($stop->sacco_route_ids)) {
                            $stop->delete();
                        } else {
                            $stop->save();
                        }
                    });
                    $pc->delete();

                    $published[] = $saccoRouteId;   // <— define the ID we just published
                });
            } catch (\Throwable $e) {
                $failed[] = [
                    'post_clean_id' => $pc->id,
                    'message' => $e->getMessage(),
                ];
            }
        }

        //run php artisan directions:populate
        //php artisan directions:backfill-h3
        // run artisan commands programmatically
        // Artisan::call('directions:populate');
        // Artisan::call('directions:backfill-h3');
        $logFile = storage_path('logs/artisan_seq_background.log');
        $basePath = base_path('artisan');
        $lockFile = storage_path('logs/artisan_exec.lock');
        $cooldown = 10;

        // --- check if lock exists and is fresh ---
        if (file_exists($lockFile)) {
            $lastRun = (int) file_get_contents($lockFile);

            // If last run was within cooldown, skip
            if (time() - $lastRun < $cooldown) {
                \Log::warning("Skipped artisan exec: another run triggered within {$cooldown}s");
                return response()->json([
                    'status' => 'skipped',
                    'message' => "Another job already dispatched within last {$cooldown} seconds"
                ]);
            }
        }

        // --- update lock timestamp ---
        file_put_contents($lockFile, time());


        $osrmHost = config('services.osrm.host');
        if (!is_string($osrmHost) || $osrmHost === '') {
            $osrmHost = env('OSRM_HOST', 'http://localhost:5000');
        }
        $osrmHost = rtrim($osrmHost, '/');
        $osrmHostArg = escapeshellarg($osrmHost);

        // Build one long command that runs sequentially in the background
        $commands = [
            "php {$basePath} directions:populate >> {$logFile} 2>&1",
            "php {$basePath} directions:backfill-h3 >> {$logFile} 2>&1",
            "php {$basePath} routes:backfill-route-stop >> {$logFile} 2>&1",
            "php {$basePath} routes:seed-flag >> {$logFile} 2>&1",
            // "php {$basePath} transfers:build --host={$osrmHostArg} --cap=600 >> {$logFile} 2>&1",
            "php {$basePath} corridor:build >> {$logFile} 2>&1",
        ];

        $command = '(' . implode(' && ', $commands) . ') &';

        // Dispatch in background
        exec($command);

        \Log::info("Background sequential artisan jobs dispatched", [
            'log_file' => $logFile,
            'commands' => [
                'directions:populate',
                'directions:backfill-h3',
                'routes:backfill-route-stop',
                'routes:seed-flag',
                // sprintf('transfers:build --host=%s --cap=600', $osrmHost),
                'corridor:build',
            ]
        ]);


        // log after execution
        Log::info('Finished running directions commands', [
            'time' => now()->toDateTimeString(),
        ]);

        if (empty($failed)) {
            return response()->json([
                'published' => $published
            ], 200);
        }


        return response()->json([
            'failed' => $failed
        ], 401);
    }

    public function summary()
    {
        $rows = PostCleanSaccoRoute::withCount(['variations'])->get()->map(function ($p) {
            $saccoRouteId = $p->sacco_route_id;
            $baseRouteId = $p->route_id;

            if (!$saccoRouteId && is_string($p->route_id) && preg_match('/^[A-Z0-9]+_\d+_\d{3}$/', $p->route_id)) {
                $parts = explode('_', $p->route_id);
                $baseRouteId = $parts[1] ?? $p->route_id;
                $saccoRouteId = $p->route_id;
            }

            $sacco = Sacco::where('sacco_id', $p->sacco_id)->first();
            $route = MainRoute::find($baseRouteId);

            $user = Auth::user();

            if ($user) {
                Log::channel('post_clean_routes')->info('Published post-clean routes', [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'sacco_id' => $sacco->sacco_id ?? null,
                    'sacco_name' => $sacco->sacco_name ?? null,
                    'route_id' => $p->route_id,
                    'route_start' => $route?->route_start_stop ?? $p->route_start_stop,
                    'end' => $route?->route_end_stop ?? $p->route_end_stop,
                ]);

            }

            return [
                'group_key' => $p->sacco_id . '_' . $baseRouteId,
                'post_clean_id' => $p->id,
                'sacco_route_id' => $saccoRouteId,
                'sacco_id' => $p->sacco_id,
                'sacco_name' => $sacco?->sacco_name ?? $p->sacco_id,
                'route_id' => $baseRouteId,
                'direction_index' => $p->direction_index,
                'start' => $route?->route_start_stop ?? $p->route_start_stop,
                'end' => $route?->route_end_stop ?? $p->route_end_stop,
                'stops_count' => is_array($p->stop_ids) ? count($p->stop_ids) : 0,
                'coords_count' => is_array($p->coordinates) ? count($p->coordinates) : 0,
                'trips_count' => PostCleanTrip::where('sacco_route_id', $saccoRouteId)->count(),
                'vars_count' => $p->variations_count,
                'route_missing' => !MainRoute::find($baseRouteId),
            ];
        });

        $grouped = $rows->groupBy('group_key')->map(function ($group) {
            $first = $group->first();

            return [
                'pair_key' => $first['group_key'],
                'sacco_name' => $first['sacco_name'],
                // 'route_number' => $first['route_number'],
                'directions' => $group->map(function ($item) {
                    return [
                        'post_clean_id' => $item['post_clean_id'],
                        'sacco_route_id' => $item['sacco_route_id'],
                        'direction_index' => $item['direction_index'],
                        'start' => $item['start'],
                        'end' => $item['end'],
                        'stops_count' => $item['stops_count'],
                        'coords_count' => $item['coords_count'],
                        'trips_count' => $item['trips_count'],
                        'vars_count' => $item['vars_count'],
                        'route_missing' => $item['route_missing'],
                    ];
                })->values(),
            ];
        })->values();

        return response()->json($grouped);
    }
}
