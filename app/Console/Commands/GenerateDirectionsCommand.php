<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Route;
use App\Models\SaccoRoute;
use App\Models\Direction;
use App\Models\DirectionThread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateDirectionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'directions:generate {--force : Overwrite existing directions} {--create : Create directions from routes} {--clean : Remove invalid directions} {--hubs : Create directions between hubs} {--import-json : Import directions from routes.json} {--test : Dry run to see how many would be created}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate direction pages for all routes using a representative Sacco route.';

    protected $stationRaptor;

    public function __construct(\App\Services\StationRaptor $stationRaptor)
    {
        parent::__construct();
        $this->stationRaptor = $stationRaptor;
    }

    // ... (handle and other methods remain same)

    public function handle()
    {
        if ($this->option('clean')) {
            $this->handleCleanup();
        }

        if ($this->option('create')) {
            $this->handleCreation();
        }

        if ($this->option('hubs')) {
            $this->handleHubs();
        }

        if ($this->option('import-json')) {
            $this->handleImportJson();
        }

        if (!$this->option('clean') && !$this->option('create') && !$this->option('hubs') && !$this->option('import-json')) {
            $this->info("Please specify a flag: --create, --clean, --hubs, or --import-json");
        }
    }

    public function __invoke()
    {
        $this->handle();
    }

    protected function handleImportJson()
    {
        $jsonPath = base_path('../frontend/public/content/directions/routes.json');
        if (!file_exists($jsonPath)) {
            $this->error("routes.json not found at: {$jsonPath}");
            return;
        }

        $content = json_decode(file_get_contents($jsonPath), true);
        $guides = $content['guides'] ?? [];

        $this->info("Found " . count($guides) . " guides in JSON. Importing...");

        $count = 0;
        foreach ($guides as $guide) {
            $originCoords = $guide['originCoords'] ?? null;
            $destCoords = $guide['destinationCoords'] ?? null;

            if (!$originCoords || !$destCoords) {
                $this->warn("Skipping {$guide['originSlug']} -> {$guide['destinationSlug']}: Missing coordinates.");
                continue;
            }

            $oStop = $this->findNearestStop($originCoords[0], $originCoords[1]);
            $dStop = $this->findNearestStop($destCoords[0], $destCoords[1]);

            if (!$oStop || !$dStop) {
                $this->warn("Skipping {$guide['originSlug']} -> {$guide['destinationSlug']}: Could not find nearest stops.");
                continue;
            }

            if ($this->option('test')) {
                $this->line("[Test] Would import: {$guide['originSlug']} -> {$guide['destinationSlug']} (Mapped to: {$oStop->stop_name} -> {$dStop->stop_name})");
            } else {
                \App\Models\DirectionThread::updateOrCreate(
                    [
                        'origin_slug' => $guide['originSlug'],
                        'destination_slug' => $guide['destinationSlug'],
                    ],
                    [
                        'origin_stop_id' => $oStop->stop_id,
                        'destination_stop_id' => $dStop->stop_id
                    ]
                );
                $this->line("Imported: {$guide['originSlug']} -> {$guide['destinationSlug']}");
            }
            $count++;
        }
        $this->info("Imported {$count} directions from JSON.");
    }

    private function findNearestStop($lat, $lng)
    {
        static $allStops = null;
        if ($allStops === null) {
            // Load all stops into memory (id, lat, lng, name)
            // Assuming < 10k stops, this is fine for a CLI command.
            $allStops = DB::table('stops')
                ->select('stop_id', 'stop_name', 'stop_lat', 'stop_long')
                ->get();
        }

        $closest = null;
        $minDist = INF;

        foreach ($allStops as $stop) {
            $dist = ($lat - $stop->stop_lat) ** 2 + ($lng - $stop->stop_long) ** 2;
            if ($dist < $minDist) {
                $minDist = $dist;
                $closest = $stop;
            }
        }

        return $closest;
    }

    protected function handleCleanup()
    {
        $this->info("Starting cleanup of invalid directions..." . ($this->option('test') ? " [DRY RUN]" : ""));

        if (!$this->option('test') && !$this->confirm('This will DELETE direction pages that have no valid route. Are you sure?')) {
            return;
        }

        // Resolve RoutePlannerController manually
        $routePlanner = app(\App\Http\Controllers\RoutePlannerController::class);

        // We don't need to load StationRaptor manually anymore as RoutePlanner handles it
        // $this->stationRaptor->loadData(); 

        $threads = DirectionThread::all();
        $deleted = 0;

        foreach ($threads as $thread) {
            // Use stored stop IDs if available
            $oStopId = $thread->origin_stop_id;
            $dStopId = $thread->destination_stop_id;

            $oStop = null;
            $dStop = null;

            if ($oStopId && $dStopId) {
                $oStop = \App\Models\Stops::where('stop_id', $oStopId)->first();
                $dStop = \App\Models\Stops::where('stop_id', $dStopId)->first();
            } else {
                // Fallback to name matching
                $oName = str_replace('-', ' ', $thread->origin_slug);
                $dName = str_replace('-', ' ', $thread->destination_slug);
                $oStop = \App\Models\Stops::where('stop_name', 'LIKE', $oName)->first();
                $dStop = \App\Models\Stops::where('stop_name', 'LIKE', $dName)->first();
            }

            if ($oStop && $dStop) {
                $start = microtime(true);

                // Construct Request for RoutePlanner
                $request = new \Illuminate\Http\Request();
                $request->merge([
                    'start_lat' => $oStop->stop_lat,
                    'start_lng' => $oStop->stop_long,
                    'end_lat' => $dStop->stop_lat,
                    'end_lng' => $dStop->stop_long,
                    'include_walking' => true
                ]);

                // Call RoutePlanner
                // Note: multilegRoute returns a JsonResponse
                $response = $routePlanner->multilegRoute($request);
                $data = $response->getData(true); // true for associative array

                $time = round(microtime(true) - $start, 4);

                // Check if routes found
                // The API returns { 'single_leg': [], 'multi_leg': [...] } or { 'message': 'No route found', ... }
                $found = false;
                if (isset($data['multi_leg']) && !empty($data['multi_leg'])) {
                    $found = true;
                }
                // Also check single_leg if applicable (though usually empty for long distance)
                if (isset($data['single_leg']) && !empty($data['single_leg'])) {
                    $found = true;
                }

                if (!$found) {
                    if ($this->option('test')) {
                        $this->line("[Test] Would delete: {$thread->origin_slug} -> {$thread->destination_slug} (Search took {$time}s, Result: No Route)");
                    } else {
                        $this->warn("Deleting invalid thread: {$thread->origin_slug} -> {$thread->destination_slug} (Search took {$time}s)");
                        $thread->delete();
                    }
                    $deleted++;
                } else {
                    if ($this->option('test')) {
                        $this->line("[Test] Valid: {$thread->origin_slug} -> {$thread->destination_slug} (Found " . count($data['multi_leg'] ?? []) . " routes)");
                    }
                }
            } else {
                // Cannot verify if stops missing
                if ($this->option('test')) {
                    $this->line("[Test] Skipping verification: {$thread->origin_slug} -> {$thread->destination_slug} (Stops not found in DB)");
                }
            }
        }

        $this->info("Deleted {$deleted} invalid direction pages.");
    }

    protected function createDirectionPage($originName, $destName, $originStopId = null, $destStopId = null)
    {
        $originSlug = \Illuminate\Support\Str::slug($originName);
        $destinationSlug = \Illuminate\Support\Str::slug($destName);

        \App\Models\DirectionThread::updateOrCreate(
            [
                'origin_slug' => $originSlug,
                'destination_slug' => $destinationSlug,
            ],
            [
                'origin_stop_id' => $originStopId,
                'destination_stop_id' => $destStopId
            ]
        );

        $this->line("Created: {$originName} -> {$destName}");
    }

    private function findNearestHub($lat, $lng)
    {
        // Simple Haversine nearest neighbor
        // Optimization: We could cache all hubs in memory since there are not many.
        static $allHubs = null;
        if ($allHubs === null) {
            $allHubs = DB::table('transit_hubs')
                ->join('stops', 'transit_hubs.stop_id', '=', 'stops.stop_id')
                ->select('transit_hubs.stop_id', 'stops.stop_name', 'stops.stop_lat', 'stops.stop_long')
                ->get();
        }

        $closest = null;
        $minDist = INF;

        foreach ($allHubs as $hub) {
            $dist = ($lat - $hub->stop_lat) ** 2 + ($lng - $hub->stop_long) ** 2; // Squared Euclidean is enough for comparison
            if ($dist < $minDist) {
                $minDist = $dist;
                $closest = $hub;
            }
        }

        // Threshold: 50km? If nearest hub is too far, maybe it's not relevant.
        // But for now, just return the closest.
        return $closest;
    }

    private function haversine($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
