<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\PreCleanSaccoRoute;
use App\Models\PreCleanTrip;

$id = 826;
$route = PreCleanSaccoRoute::find($id);

if (!$route) {
    echo "Route $id not found.\n";
    exit;
}

echo "Route ID: " . $route->id . "\n";
echo "Sacco Route ID: " . $route->sacco_route_id . "\n";
echo "Route Number: " . $route->route_number . "\n";
echo "Stop IDs: " . json_encode($route->stop_ids) . "\n";

if (!empty($route->stop_ids)) {
    $stops = \App\Models\PreCleanStop::whereIn('id', $route->stop_ids)->get();
    echo "Stops Found: " . $stops->count() . "\n";
    foreach ($stops as $stop) {
        echo "- Stop ID: " . $stop->id . " (" . $stop->stop_name . ")\n";
    }
} else {
    echo "No stop_ids in route.\n";
}

$trips = PreCleanTrip::where('sacco_route_id', $route->sacco_route_id)->get();

echo "Trips Count (by sacco_route_id): " . $trips->count() . "\n";

if ($trips->count() > 0) {
    echo "Trips Data:\n";
    foreach ($trips as $trip) {
        echo "- Trip ID: " . $trip->id . "\n";
        print_r($trip->toArray());
    }
} else {
    echo "No trips found for sacco_route_id: " . $route->sacco_route_id . "\n";
}
