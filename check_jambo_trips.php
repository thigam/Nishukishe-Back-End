<?php

use App\Models\Sacco;
use App\Models\SaccoRoutes;
use App\Models\Trip;
use App\Models\PreCleanSaccoRoute;
use App\Models\PostCleanSaccoRoute;
use App\Models\PreCleanTrip;
use App\Models\PostCleanTrip;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$saccoName = 'Jambo Mambo';
$sacco = Sacco::where('sacco_name', 'LIKE', "%$saccoName%")->first();

if (!$sacco) {
    echo "Sacco '$saccoName' not found.\n";
    exit;
}

echo "Found Sacco: {$sacco->sacco_name} (ID: {$sacco->sacco_id})\n";

// Check Live Routes
$liveRoutes = SaccoRoutes::where('sacco_id', $sacco->sacco_id)->get();
echo "\n--- Live Routes (" . $liveRoutes->count() . ") ---\n";
foreach ($liveRoutes as $route) {
    echo "Route: {$route->sacco_route_id} ({$route->route_id})\n";
    echo "  From: {$route->route_start_stop} To: {$route->route_end_stop}\n";
    $trips = Trip::where('sacco_route_id', $route->sacco_route_id)->get();
    echo "  Trips: " . $trips->count() . "\n";
    foreach ($trips as $trip) {
        echo "    - Start: {$trip->start_time}\n";
        $times = $trip->stop_times;
        if (is_string($times))
            $times = json_decode($times, true);

        $stops = [];
        foreach ($times as $t) {
            $stop = \App\Models\Stops::where('stop_id', $t['stop_id'])->first();
            $name = $stop ? $stop->stop_name : $t['stop_id'];
            $stops[] = "{$name} ({$t['time']})";
        }
        echo "    - Stops: " . implode(' -> ', $stops) . "\n";
    }
}

// Check Post-Clean Routes
$postRoutes = PostCleanSaccoRoute::where('sacco_id', $sacco->sacco_id)->get();
echo "\n--- Post-Clean Routes (" . $postRoutes->count() . ") ---\n";
foreach ($postRoutes as $route) {
    echo "Route: {$route->sacco_route_id} (PreID: {$route->pre_clean_id})\n";
    $trips = PostCleanTrip::where('sacco_route_id', $route->sacco_route_id)->get();
    echo "  Trips: " . $trips->count() . "\n";
    foreach ($trips as $trip) {
        // PostCleanTrip might store times differently (json?)
        $times = is_string($trip->trip_times) ? json_decode($trip->trip_times, true) : $trip->trip_times;
        $count = is_array($times) ? count($times) : 0;
        echo "    - Trip count in record: $count\n";
    }
}

// Check Pre-Clean Routes
$preRoutes = PreCleanSaccoRoute::where('sacco_id', $sacco->sacco_id)->get();
echo "\n--- Pre-Clean Routes (" . $preRoutes->count() . ") ---\n";
foreach ($preRoutes as $route) {
    echo "Route: {$route->sacco_route_id} (Status: {$route->status})\n";
    $trips = PreCleanTrip::where('sacco_route_id', $route->sacco_route_id)->get();
    echo "  Trips: " . $trips->count() . "\n";
}
