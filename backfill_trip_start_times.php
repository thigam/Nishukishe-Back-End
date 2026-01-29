<?php

use App\Models\Trip;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$trips = Trip::whereNull('start_time')->orWhere('start_time', '')->get();
echo "Found " . $trips->count() . " trips with missing start_time.\n";

foreach ($trips as $trip) {
    $times = $trip->stop_times;
    if (is_string($times))
        $times = json_decode($times, true);

    if (!empty($times) && is_array($times)) {
        $first = $times[0] ?? null;
        if ($first && isset($first['time'])) {
            $trip->start_time = $first['time'];
            $trip->save();
            echo "Updated Trip ID {$trip->id} start_time to {$first['time']}\n";
        }
    }
}

echo "Done.\n";
