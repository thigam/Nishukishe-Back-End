<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

$baseUrl = 'https://api.iabiri.com/appApiV1';
$authToken = '4F5D3QC5-C94A-CFD5-87C1-4E2903311DF0';

echo "Fetching cities...\n";
$response = Http::withHeaders([
    'Authorization' => $authToken,
    'User-Agent' => 'Mozilla/5.0'
])->post("{$baseUrl}/common/getCity", []);

if ($response->failed()) {
    echo "Failed to fetch cities: " . $response->body() . "\n";
    exit(1);
}

$cities = $response->json()['data'] ?? [];
echo "Found " . count($cities) . " cities.\n";

$nairobiId = null;
$mombasaId = null;

foreach ($cities as $city) {
    if (stripos($city['name'], 'Nairobi') !== false) {
        echo "Found Nairobi: " . $city['name'] . " (ID: " . $city['id'] . ")\n";
        $nairobiId = $city['id'];
    }
    if (stripos($city['name'], 'Mombasa') !== false) {
        echo "Found Mombasa: " . $city['name'] . " (ID: " . $city['id'] . ")\n";
        $mombasaId = $city['id'];
    }
}

if (!$nairobiId || !$mombasaId) {
    echo "Could not resolve cities.\n";
    exit(1);
}

echo "Searching for trips on 2026-01-30...\n";
$searchResponse = Http::withHeaders([
    'Authorization' => $authToken,
    'User-Agent' => 'Mozilla/5.0',
    'Content-Type' => 'application/json'
])->post("{$baseUrl}/booking/filterBuses", [
            'from_city_id' => $nairobiId,
            'to_city_id' => $mombasaId,
            'travel_date' => '2026-01-30'
        ]);

if ($searchResponse->failed()) {
    echo "Search failed: " . $searchResponse->body() . "\n";
    exit(1);
}

$data = $searchResponse->json();
echo "Search Response Keys: " . implode(', ', array_keys($data)) . "\n";
if (isset($data['data'])) {
    echo "Found " . count($data['data']) . " buses.\n";
    if (count($data['data']) > 0) {
        print_r($data['data'][0]);
    }
} else {
    echo "No 'data' key in response.\n";
    print_r($data);
}
