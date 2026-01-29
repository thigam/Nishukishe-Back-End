<?php

use App\Models\Sacco;
use App\Models\SaccoRoutes;
use App\Models\Stops;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 1. Find Sacco
$saccoName = 'Jambo Mambo'; // Approximate name
$sacco = Sacco::where('sacco_name', 'LIKE', "%{$saccoName}%")->first();

if (!$sacco) {
    echo "Sacco '{$saccoName}' not found.\n";
    exit;
}

echo "Found Sacco: {$sacco->sacco_name} (ID: {$sacco->sacco_id})\n";

// 2. Find Routes for this Sacco
$routes = SaccoRoutes::where('sacco_id', $sacco->sacco_id)->get();

if ($routes->isEmpty()) {
    echo "No routes found for this Sacco.\n";
    exit;
}

echo "Found " . $routes->count() . " routes.\n";

foreach ($routes as $route) {
    echo "Route: {$route->route_id} (SaccoRouteID: {$route->sacco_route_id})\n";
    echo "  Stops: " . implode(', ', $route->stop_ids ?? []) . "\n";

    // Check if stops are in corr_station_members
    $stopIds = $route->stop_ids ?? [];
    if (empty($stopIds))
        continue;

    $missingInCorr = [];
    foreach ($stopIds as $stopId) {
        $exists = DB::table('corr_station_members')->where('stop_id', $stopId)->exists();
        if (!$exists) {
            $missingInCorr[] = $stopId;
        }
    }

    if (!empty($missingInCorr)) {
        echo "  WARNING: The following stops are MISSING from corr_station_members: " . implode(', ', $missingInCorr) . "\n";
    } else {
        echo "  OK: All stops present in corr_station_members.\n";
    }
}
