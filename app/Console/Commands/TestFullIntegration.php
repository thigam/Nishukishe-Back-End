<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\RoutePlannerController;
use Illuminate\Http\Request;

class TestFullIntegration extends Command
{
    protected $signature = 'test:full-integration';
    protected $description = 'Test the full RoutePlannerController with StationRaptor integration';

    public function handle(RoutePlannerController $planner)
    {
        $this->info("Starting Full Integration Test...");

        // Westlands (The Mall) -> Kitengela (Naivas)
        $coords = [
            'start_lat' => -1.268307,
            'start_lng' => 36.811068,
            'end_lat' => -1.492063,
            'end_lng' => 36.977463
        ];

        $req = new Request($coords);

        $start = microtime(true);

        try {
            $response = $planner->multilegRoute($req);
            $end = microtime(true);

            $data = $response->getData(true);
            $routes = $data['multi_leg'] ?? []; // Controller returns 'multi_leg' key now?
            // Check controller return: ['single_leg' => [], 'multi_leg' => $finalResults]

            $this->info("Time Taken: " . number_format($end - $start, 4) . "s");
            $this->info("Routes Found: " . count($routes));

            if (count($routes) > 0) {
                foreach ($routes as $i => $route) {
                    $this->info("\n--- Route " . ($i + 1) . " (" . ($route['summary']['total_duration_minutes'] ?? '?') . " min) ---");
                    foreach ($route['legs'] as $leg) {
                        $mode = strtoupper($leg['mode']);
                        $from = $leg['from_stop']['stop_name'] ?? $leg['board_stop']['stop_name'] ?? '?';
                        $to = $leg['to_stop']['stop_name'] ?? $leg['alight_stop']['stop_name'] ?? '?';
                        $info = $mode === 'BUS' ? "Route: " . ($leg['route_number'] ?? $leg['sacco_route_id']) : ($leg['minutes'] . " min");

                        $this->line(" $mode: $from -> $to ($info)");
                    }
                }
            } else {
                $this->warn("No routes found.");
                if (isset($data['message'])) {
                    $this->warn("Message: " . $data['message']);
                }
            }

        } catch (\Throwable $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error($e->getTraceAsString());
        }
    }
}
