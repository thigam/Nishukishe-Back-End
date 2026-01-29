<?php

use App\Models\SaccoRoutes;
use Illuminate\Support\Facades\DB;

// Nairobi Stops
$origins = ['485', '0810VIU', '0810MCP', 'ST_S0126656_E3680435'];
// Kajiado Stops
$dests = ['ST_S0185257_E3678861', 'ST_S0184970_E3678909', 'ST_S0185079_E3679024', 'ST_S0184716_E3679159'];

echo "Checking for direct routes...\n";

$routes = SaccoRoutes::whereNotNull('stop_ids')->get();
$found = 0;

foreach ($routes as $r) {
    $stops = $r->stop_ids;
    if (!is_array($stops))
        continue;

    $hasOrigin = false;
    $hasDest = false;
    $oIdx = -1;
    $dIdx = -1;

    foreach ($stops as $idx => $sid) {
        if (in_array($sid, $origins)) {
            $hasOrigin = true;
            $oIdx = $idx;
        }
        if (in_array($sid, $dests)) {
            $hasDest = true;
            $dIdx = $idx;
        }
    }

    if ($hasOrigin && $hasDest && $oIdx < $dIdx) {
        echo "Found Direct Route: " . $r->sacco_route_id . " (" . $r->route_name . ")\n";
        echo " - Origin Stop: " . $stops[$oIdx] . "\n";
        echo " - Dest Stop: " . $stops[$dIdx] . "\n";
        $found++;
    }
}

if ($found == 0) {
    echo "No direct routes found between these specific stop sets.\n";
}
