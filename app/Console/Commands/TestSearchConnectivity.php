<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SearchLog;
use App\Http\Controllers\RoutePlannerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TestSearchConnectivity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-search-connectivity
                            {--export : Export random failing searches to a JSON file}
                            {--file= : JSON file containing searches to test}
                            {--limit=100 : Maximum number of searches to test}
                            {--refresh : Clear the StationRaptor cache before running}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the search routing logic against random or file-based historical failing searches';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('refresh')) {
            \Illuminate\Support\Facades\Cache::forget('station_raptor_data_v1');
            $this->info("Cleared StationRaptor data cache.");
        }

        if ($this->option('export')) {
            return $this->exportFailing();
        }

        return $this->runTests();
    }

    /**
     * Export failing searches to a JSON file.
     */
    private function exportFailing()
    {
        $limit = (int) $this->option('limit');
        $this->info("Exporting up to {$limit} random failing searches from DB...");

        $rawSearches = SearchLog::where('has_result', false)
            ->where(function ($q) {
                $q->whereNotNull('origin_lat')
                  ->orWhereNotNull('query');
            })
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        $formatted = [];
        foreach ($rawSearches as $s) {
            $olat = null;
            $olng = null;
            if (!empty($s->origin_lat)) {
                $olat = (float) $s->origin_lat;
                $olng = (float) $s->origin_lng;
            } elseif (!empty($s->query['origin'])) {
                $olat = (float) ($s->query['origin'][0] ?? 0);
                $olng = (float) ($s->query['origin'][1] ?? 0);
            }

            $dlat = null;
            $dlng = null;
            if (!empty($s->destination_lat)) {
                $dlat = (float) $s->destination_lat;
                $dlng = (float) $s->destination_lng;
            } elseif (!empty($s->query['destination'])) {
                $dlat = (float) ($s->query['destination'][0] ?? 0);
                $dlng = (float) ($s->query['destination'][1] ?? 0);
            }

            if ($olat && $olng && $dlat && $dlng) {
                $formatted[] = [
                    'origin_lat' => $olat,
                    'origin_lng' => $olng,
                    'destination_lat' => $dlat,
                    'destination_lng' => $dlng,
                ];
            }
        }

        if (empty($formatted)) {
            $this->error("No failing searches with valid coordinates found in the SearchLog table.");
            return self::FAILURE;
        }

        $filePath = storage_path('app/failing_searches.json');
        file_put_contents($filePath, json_encode($formatted, JSON_PRETTY_PRINT));

        $this->info("Successfully exported " . count($formatted) . " searches to {$filePath}");
        return self::SUCCESS;
    }

    /**
     * Run search queries and output metrics.
     */
    private function runTests()
    {
        $file = $this->option('file');
        $limit = (int) $this->option('limit');
        $searches = [];

        if ($file) {
            if (!file_exists($file)) {
                $this->error("Specified file not found: {$file}");
                return self::FAILURE;
            }
            $this->info("Loading searches from file: {$file}");
            $data = json_decode(file_get_contents($file), true);
            if (!is_array($data)) {
                $this->error("Invalid JSON format in file.");
                return self::FAILURE;
            }
            $searches = array_slice($data, 0, $limit);
        } else {
            $this->info("Fetching up to {$limit} random failing searches directly from DB...");
            $rawSearches = SearchLog::where('has_result', false)
                ->where(function ($q) {
                    $q->whereNotNull('origin_lat')
                      ->orWhereNotNull('query');
                })
                ->inRandomOrder()
                ->limit($limit)
                ->get();

            foreach ($rawSearches as $s) {
                $olat = null;
                $olng = null;
                if (!empty($s->origin_lat)) {
                    $olat = (float) $s->origin_lat;
                    $olng = (float) $s->origin_lng;
                } elseif (!empty($s->query['origin'])) {
                    $olat = (float) ($s->query['origin'][0] ?? 0);
                    $olng = (float) ($s->query['origin'][1] ?? 0);
                }

                $dlat = null;
                $dlng = null;
                if (!empty($s->destination_lat)) {
                    $dlat = (float) $s->destination_lat;
                    $dlng = (float) $s->destination_lng;
                } elseif (!empty($s->query['destination'])) {
                    $dlat = (float) ($s->query['destination'][0] ?? 0);
                    $dlng = (float) ($s->query['destination'][1] ?? 0);
                }

                if ($olat && $olng && $dlat && $dlng) {
                    $searches[] = [
                        'origin_lat' => $olat,
                        'origin_lng' => $olng,
                        'destination_lat' => $dlat,
                        'destination_lng' => $dlng,
                    ];
                }
            }
        }

        $total = count($searches);
        if ($total === 0) {
            $this->error("No searches to test.");
            return self::FAILURE;
        }

        $this->info("Starting search connectivity test on {$total} searches...");
        \Illuminate\Support\Facades\DB::connection()->disableQueryLog();

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $successCount = 0;
        $durations = [];
        $resultsLog = [];

        foreach ($searches as $idx => $s) {
            $olat = (float) ($s['origin_lat'] ?? $s['start_lat'] ?? 0);
            $olng = (float) ($s['origin_lng'] ?? $s['start_lng'] ?? 0);
            $dlat = (float) ($s['destination_lat'] ?? $s['end_lat'] ?? 0);
            $dlng = (float) ($s['destination_lng'] ?? $s['end_lng'] ?? 0);

            if ($olat === 0.0 || $dlat === 0.0) {
                $bar->advance();
                continue;
            }

            $request = Request::create('/api/route', 'POST', [
                'origin' => [$olat, $olng],
                'destination' => [$dlat, $dlng],
                'include_walking' => true,
            ]);

            $start = microtime(true);
            try {
                $controller = app(RoutePlannerController::class);
                $response = $controller->multilegRoute($request);
                $end = microtime(true);
                $durationMs = ($end - $start) * 1000;
                $durations[] = $durationMs;

                $content = json_decode($response->getContent(), true);
                $routes = array_merge($content['single_leg'] ?? [], $content['multi_leg'] ?? []);
                $hasResult = !empty($routes);
                if ($hasResult) {
                    $successCount++;
                }

                $resultsLog[] = [
                    'index' => $idx + 1,
                    'origin' => "{$olat},{$olng}",
                    'destination' => "{$dlat},{$dlng}",
                    'has_result' => $hasResult ? 'YES' : 'NO',
                    'routes_count' => count($routes),
                    'time_ms' => round($durationMs, 2),
                    'reason' => $hasResult ? 'Success' : $this->getDiagnosticReason($controller, $olat, $olng, $dlat, $dlng),
                ];
            } catch (\Throwable $e) {
                $end = microtime(true);
                $durationMs = ($end - $start) * 1000;
                $durations[] = $durationMs;
                $resultsLog[] = [
                    'index' => $idx + 1,
                    'origin' => "{$olat},{$olng}",
                    'destination' => "{$dlat},{$dlng}",
                    'has_result' => 'ERROR',
                    'routes_count' => 0,
                    'time_ms' => round($durationMs, 2),
                    'reason' => 'Exception: ' . $e->getMessage(),
                ];
                Log::error("Test search failed with error: " . $e->getMessage());
            }

            if (app()->bound(\App\Services\StationRaptor::class)) {
                app(\App\Services\StationRaptor::class)->clearCache();
            }
            unset($controller);
            gc_collect_cycles();
            $mem = round(memory_get_usage() / 1024 / 1024, 2);
            $this->info(" [Iteration " . ($idx + 1) . "] Memory: {$mem} MB");
            $bar->advance();
        }

        $bar->finish();
        $this->info("\n");

        // Print details
        $headers = ['Index', 'Origin', 'Destination', 'Resolved?', 'Routes Count', 'Latency (ms)', 'Failure Reason / Status'];
        $this->table($headers, $resultsLog);

        // Calculate statistics
        $avgTime = count($durations) > 0 ? array_sum($durations) : 0;
        $avgTime = count($durations) > 0 ? $avgTime / count($durations) : 0;
        $maxTime = count($durations) > 0 ? max($durations) : 0;
        $minTime = count($durations) > 0 ? min($durations) : 0;
        $successRate = $total > 0 ? ($successCount / $total) * 100 : 0;

        $this->info("\n=================================");
        $this->info("        TEST RESULTS SUMMARY     ");
        $this->info("=================================");
        $this->info("Total Searches Tested: {$total}");
        $this->info("Now Resolved:          {$successCount} / {$total}");
        $this->info("Success (Recovery) Rate: " . round($successRate, 2) . "%");
        $this->info("Average Latency:       " . round($avgTime, 2) . " ms");
        $this->info("Min Latency:           " . round($minTime, 2) . " ms");
        $this->info("Max Latency:           " . round($maxTime, 2) . " ms");
        $this->info("=================================");

        return self::SUCCESS;
    }

    /**
     * Diagnose why a search returned no routes.
     */
    private function getDiagnosticReason($controller, $olat, $olng, $dlat, $dlng)
    {
        try {
            $method = new \ReflectionMethod(RoutePlannerController::class, 'seedStopsWithHubs');
            $method->setAccessible(true);

            $originStops = $method->invoke($controller, (float) $olat, (float) $olng, 3, 6, 2, 5);
            $destStops = $method->invoke($controller, (float) $dlat, (float) $dlng, 3, 6, 2, 5);

            if ($originStops->isEmpty()) {
                return "No origin seed stops found (out of bounds)";
            }
            if ($destStops->isEmpty()) {
                return "No destination seed stops found (out of bounds)";
            }

            $stationRaptor = app(\App\Services\StationRaptor::class);
            $stationRaptor->loadData();

            $refProp = new \ReflectionProperty(\App\Services\StationRaptor::class, 'stopToStation');
            $refProp->setAccessible(true);
            $stopToStation = $refProp->getValue($stationRaptor);

            $mappedOrigin = false;
            foreach ($originStops as $o) {
                if (isset($stopToStation[$o['stop_id']])) {
                    $mappedOrigin = true;
                }
            }

            $mappedDest = false;
            foreach ($destStops as $d) {
                if (isset($stopToStation[$d['stop_id']])) {
                    $mappedDest = true;
                }
            }

            if (!$mappedOrigin) {
                return "Origin stops not mapped to any station";
            }
            if (!$mappedDest) {
                return "Destination stops not mapped to any station";
            }

            // Trace path expansion
            $rawPaths = [];
            foreach ($originStops as $oStop) {
                foreach ($destStops as $dStop) {
                    if ($oStop['stop_id'] === $dStop['stop_id']) continue;
                    $results = $stationRaptor->search($oStop['stop_id'], $dStop['stop_id']);
                    if (!isset($results['error'])) {
                        foreach ($results as $path) {
                            $detailed = $stationRaptor->expandPath($path, $oStop['stop_id'], $dStop['stop_id']);
                            if (!empty($detailed)) {
                                $converted = [];
                                $converted[] = ['stop_id' => $oStop['stop_id'], 'label' => 'start'];
                                $lastStop = $oStop['stop_id'];
                                $valid = true;
                                foreach ($detailed as $leg) {
                                    if (!$leg['walk_valid'] || !$leg['from_stop'] || !$leg['to_stop']) {
                                        $valid = false;
                                        break;
                                    }
                                    if ($leg['from_stop'] !== $lastStop) {
                                        $converted[] = ['stop_id' => $leg['from_stop'], 'label' => 'walk 5 min'];
                                    }
                                    $converted[] = ['stop_id' => $leg['to_stop'], 'label' => 'bus via ' . $leg['route_id']];
                                    $lastStop = $leg['to_stop'];
                                }
                                if ($valid) {
                                    $sig = json_encode($converted);
                                    $rawPaths[$sig] = $converted;
                                }
                            }
                        }
                    }
                }
            }

            $rawPaths = array_values($rawPaths);
            if (empty($rawPaths)) {
                return "All path expansions failed in expandPath (checkWalkingEdge/same-station failure)";
            }

            // 1. Redundant transfers filter
            $filteredRawPaths = [];
            foreach ($rawPaths as $path) {
                $redundant = false;
                $prevSaccoRouteId = null;
                foreach ($path as $step) {
                    if (str_starts_with($step['label'], 'bus via ')) {
                        $saccoRouteId = substr($step['label'], 8);
                        if ($saccoRouteId && $prevSaccoRouteId && $saccoRouteId === $prevSaccoRouteId) {
                            $redundant = true;
                            break;
                        }
                        $prevSaccoRouteId = $saccoRouteId;
                    }
                }
                if (!$redundant) {
                    $filteredRawPaths[] = $path;
                }
            }

            if (empty($filteredRawPaths)) {
                return "Filtered out: All paths had redundant transfers (e.g. Bus A -> Bus A)";
            }

            // Populate controllers cache variables (stopsCache/routeCache) as needed
            // By calling getStopLL/etc. or using Reflection
            $refEnrich = new \ReflectionMethod(RoutePlannerController::class, 'enrichPath');
            $refEnrich->setAccessible(true);

            // Populate Route cache
            $uniqueRouteIds = [];
            $uniqueStopIds = [];
            foreach ($filteredRawPaths as $path) {
                foreach ($path as $step) {
                    if (isset($step['stop_id'])) {
                        $uniqueStopIds[] = (string) $step['stop_id'];
                    }
                    if (isset($step['label']) && str_starts_with($step['label'], 'bus via ')) {
                        $uniqueRouteIds[] = substr($step['label'], 8);
                    }
                }
            }
            $uniqueRouteIds = array_values(array_unique(array_filter($uniqueRouteIds)));
            if (!empty($uniqueRouteIds)) {
                $routes = \App\Models\SaccoRoutes::with(['sacco', 'route', 'variations'])
                    ->whereIn('sacco_route_id', $uniqueRouteIds)
                    ->get()
                    ->keyBy('sacco_route_id');

                $refRouteCache = new \ReflectionProperty(RoutePlannerController::class, 'routeCache');
                $refRouteCache->setAccessible(true);
                $routeCache = $refRouteCache->getValue($controller);
                foreach ($routes as $srid => $r) {
                    $routeCache[$srid] = $r;
                    $stopsArr = is_array($r->stop_ids) ? $r->stop_ids : [];
                    if (count($stopsArr) >= 2) {
                        $uniqueStopIds[] = (string) $stopsArr[0];
                        $uniqueStopIds[] = (string) $stopsArr[count($stopsArr) - 1];
                    }
                }
                $refRouteCache->setValue($controller, $routeCache);
            }

            $uniqueStopIds = array_values(array_unique(array_filter($uniqueStopIds)));
            if (!empty($uniqueStopIds)) {
                $stops = \App\Models\Stops::whereIn('stop_id', $uniqueStopIds)->get()->keyBy('stop_id');
                $refStopsCache = new \ReflectionProperty(RoutePlannerController::class, 'stopsCache');
                $refStopsCache->setAccessible(true);
                $stopsCache = $refStopsCache->getValue($controller);
                foreach ($stops as $sid => $s) {
                    $stopsCache[$sid] = $s;
                }
                $refStopsCache->setValue($controller, $stopsCache);
            }

            $enriched = [];
            foreach ($filteredRawPaths as $p) {
                $enriched[] = $refEnrich->invoke($controller, $p, null, false);
            }

            $hasLongDistanceFraction = false;
            $keptAfterFraction = [];
            foreach ($enriched as $route) {
                $ld = false;
                foreach ($route['legs'] ?? [] as $leg) {
                    if (!empty($leg['is_long_distance_fraction'])) {
                        $ld = true;
                        break;
                    }
                }
                if ($ld) {
                    $hasLongDistanceFraction = true;
                } else {
                    $keptAfterFraction[] = $route;
                }
            }

            if (empty($keptAfterFraction) && $hasLongDistanceFraction) {
                return "Filtered out: is_long_distance_fraction (fare > 200 KES used for < 45 km)";
            }

            // 3. Access/Egress capping
            $refAccess = new \ReflectionMethod(RoutePlannerController::class, 'buildAccessWalk');
            $refAccess->setAccessible(true);
            $refEgress = new \ReflectionMethod(RoutePlannerController::class, 'buildEgressWalk');
            $refEgress->setAccessible(true);

            $keptAfterBookends = [];
            $hadCappedBookends = false;

            $routesToProcess = !empty($keptAfterFraction) ? $keptAfterFraction : $enriched;

            foreach ($routesToProcess as $it) {
                $legs = $it['legs'] ?? [];
                if (!$legs) {
                    $keptAfterBookends[] = $it;
                    continue;
                }
                $accessCapped = false;
                $egressCapped = false;

                $accessArgs = [$legs[0], (float) $olat, (float) $olng, &$accessCapped];
                $first = $refAccess->invokeArgs($controller, $accessArgs);

                $egressArgs = [$legs[count($legs) - 1], (float) $dlat, (float) $dlng, &$egressCapped];
                $last = $refEgress->invokeArgs($controller, $egressArgs);

                if ($accessCapped || $egressCapped) {
                    $hadCappedBookends = true;
                    continue;
                }
                $keptAfterBookends[] = $it;
            }

            if (empty($keptAfterBookends) && $hadCappedBookends) {
                return "Filtered out: Access/Egress walk distance capped (> 5 km)";
            }

            // 4. Outliers filter
            $refOutliers = new \ReflectionMethod(RoutePlannerController::class, 'filterOutliers');
            $refOutliers->setAccessible(true);
            $keptAfterOutliers = $refOutliers->invoke($controller, $keptAfterBookends);

            if (empty($keptAfterOutliers) && !empty($keptAfterBookends)) {
                return "Filtered out: Discarded as statistical outliers (too long/expensive)";
            }

            return "Filtered out: unknown reason";

        } catch (\Throwable $e) {
            return "Diagnostic failed: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine();
        }
    }
}
