<?php

use App\Services\StationRaptor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Cache::forget('station_raptor_data_v1');
// echo "Cleared StationRaptor cache.\n";

$raptor = app(StationRaptor::class);
$raptor->loadData();
echo "Loaded StationRaptor data.\n";

// Test Route: JA0001_80000114011_001
// Origin: ST_S0129207_E3682195
// Dest: ST_S0110850_E3664213

$origin = 'ST_S0129207_E3682195';
$dest = 'ST_S0110850_E3664213';

echo "Searching from $origin to $dest...\n";

$results = $raptor->search($origin, $dest);

if (isset($results['error'])) {
    echo "Search Error: " . $results['error'] . "\n";
} else {
    echo "Found " . count($results) . " paths.\n";
    $foundJambo = false;
    foreach ($results as $i => $path) {
        $isJambo = false;
        foreach ($path as $leg) {
            if (str_contains($leg['route_id'], 'JA0001')) {
                $isJambo = true;
                $foundJambo = true;
            }
        }

        if ($isJambo) {
            echo "Path $i (JAMBO MAMBO):\n";
            foreach ($path as $leg) {
                echo "  {$leg['from_station']} -> {$leg['to_station']} via {$leg['route_id']}\n";
            }

            // Try expanding
            $detailed = $raptor->expandPath($path, $origin, $dest);
            if (empty($detailed)) {
                echo "  WARNING: Expansion failed for this path.\n";
            } else {
                echo "  Expansion successful.\n";
            }
        }
    }

    if (!$foundJambo) {
        echo "WARNING: No Jambo Mambo (JA0001) routes found in the results.\n";
    }
}
