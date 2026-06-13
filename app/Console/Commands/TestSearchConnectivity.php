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
                            {--limit=100 : Maximum number of searches to test}';

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
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // Instantiate controller
        $controller = app(RoutePlannerController::class);
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
                $response = $controller->multilegRoute($request);
                $end = microtime(true);
                $durationMs = ($end - $start) * 1000;
                $durations[] = $durationMs;

                $content = json_decode($response->getContent(), true);
                $routes = $content['routes'] ?? [];
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
                ];
                Log::error("Test search failed with error: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->info("\n");

        // Print details
        $headers = ['Index', 'Origin', 'Destination', 'Resolved?', 'Routes Count', 'Latency (ms)'];
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
}
