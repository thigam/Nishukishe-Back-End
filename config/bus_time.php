<?php

return [
    'enabled' => env('BUS_TIME_ENABLED', false),
    'base_url' => env('BUS_TIME_BASE_URL', ''),
    'endpoint' => env('BUS_TIME_ENDPOINT', ''),
    'api_key' => env('BUS_TIME_API_KEY'),
    'timeout' => env('BUS_TIME_TIMEOUT', 2.0),
];
