<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\PreCleanSaccoRoute;
use App\Models\PreCleanTrip;

$latestRoute = PreCleanSaccoRoute::latest()->first();

if (!$latestRoute) {
    echo "No PreCleanSaccoRoute found.\n";
    exit;
}

echo "Latest Route ID: " . $latestRoute->id . "\n";
echo "Sacco Route ID: " . $latestRoute->sacco_route_id . "\n";
echo "Route Number: " . $latestRoute->route_number . "\n";
echo "Created At: " . $latestRoute->created_at . "\n";

$trips = PreCleanTrip::where('sacco_route_id', $latestRoute->sacco_route_id)->get();

echo "Trips Count: " . $trips->count() . "\n";

if ($trips->count() > 0) {
    echo "Trips Data:\n";
    foreach ($trips as $trip) {
        echo "- Trip ID: " . $trip->id . ", Stop Times Count: " . count($trip->stop_times ?? []) . "\n";
        print_r($trip->toArray());
    }
} else {
    echo "No trips found for this route.\n";
    // Check if maybe they are linked by ID instead of sacco_route_id (unlikely given the model, but worth checking)
    $tripsById = PreCleanTrip::where('sacco_route_id', $latestRoute->id)->get();
    echo "Trips Count (by ID): " . $tripsById->count() . "\n";
}
