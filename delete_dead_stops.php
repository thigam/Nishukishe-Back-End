<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Stops;
use App\Models\Directions;
use App\Models\SaccoRoutes;
use Illuminate\Support\Facades\DB;

echo "Preparing to DELETE Dead Stops...\n";

// 1. Identify Dead Stops (same logic)
$allStopIds = Stops::pluck('stop_id')->toArray();
$directionIds = Directions::pluck('direction_id')->toArray();
$directionSet = array_flip($directionIds);

echo "Scanning SaccoRoutes...\n";
$usedInRoutes = [];
SaccoRoutes::chunk(100, function ($routes) use (&$usedInRoutes) {
    foreach ($routes as $r) {
        if ($r->stop_ids) {
            foreach ($r->stop_ids as $sid) {
                $usedInRoutes[$sid] = true;
            }
        }
    }
});

$deadStops = [];
foreach ($allStopIds as $sid) {
    if (!isset($directionSet[$sid]) && !isset($usedInRoutes[$sid])) {
        $deadStops[] = $sid;
    }
}

$count = count($deadStops);
echo "Found $count Dead Stops.\n";

if ($count > 0) {
    echo "Deleting...\n";
    // Delete in chunks to avoid memory issues
    $chunks = array_chunk($deadStops, 500);
    foreach ($chunks as $chunk) {
        Stops::whereIn('stop_id', $chunk)->delete();
        echo ".";
    }
    echo "\nDone!\n";
} else {
    echo "Nothing to delete.\n";
}
