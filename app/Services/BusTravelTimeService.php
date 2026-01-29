<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class BusTravelTimeService
{
    private bool $enabled;
    private string $baseUrl;
    private string $endpoint;
    private ?string $apiKey;
    private float $timeout;
    private ?string $mapboxAccessToken;
    private int $dailyLimit;

    public function __construct()
    {
        $config = config('bus_time', []);

        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->endpoint = ltrim((string) ($config['endpoint'] ?? ''), '/');
        $this->apiKey = $config['api_key'] ?? null;
        $this->timeout = (float) ($config['timeout'] ?? 2.0);

        // Mapbox Config
        $this->mapboxAccessToken = config('services.mapbox.token') ?? env('NEXT_PUBLIC_MAPBOX_TOKEN');
        $this->dailyLimit = (int) ($config['daily_limit'] ?? 3000);
    }

    /**
     * Estimate bus travel minutes.
     *
     * @param array         $boardStop   ['stop_id' => string, 'lat' => float, 'lng' => float]
     * @param array         $alightStop  ['stop_id' => string, 'lat' => float, 'lng' => float]
     * @param array|null    $coordinates Array of [lat, lng] pairs describing the sampled geometry
     * @param string|null   $polyline    Optional encoded polyline supplied by callers
     * @param callable      $heuristic   Fallback to compute minutes using local heuristic
     *
     * @return array{minutes: float, source: string}
     */
    public function estimate(array $boardStop, array $alightStop, ?array $coordinates, ?string $polyline, callable $heuristic): array
    {
        $fallback = function (?string $message = null, array $context = []) use ($heuristic): array {
            if ($message) {
                Log::debug($message, $context);
            }

            try {
                $minutes = (float) $heuristic();
            } catch (\Throwable $e) {
                Log::warning('Bus travel heuristic failed', ['error' => $e->getMessage()]);
                $minutes = 0.0;
            }

            return [
                'minutes' => max(0.0, $minutes),
                'source' => 'heuristic',
            ];
        };

        if (!$this->enabled) {
            return $fallback('Bus travel time API disabled');
        }

        if ($this->baseUrl === '') {
            return $fallback('Bus travel time API missing base URL');
        }

        $url = $this->endpoint !== ''
            ? $this->baseUrl . '/' . $this->endpoint
            : $this->baseUrl;

        $payload = [
            'board_stop_id' => $boardStop['stop_id'] ?? null,
            'alight_stop_id' => $alightStop['stop_id'] ?? null,
            'board' => [
                'lat' => $boardStop['lat'] ?? null,
                'lng' => $boardStop['lng'] ?? null,
            ],
            'alight' => [
                'lat' => $alightStop['lat'] ?? null,
                'lng' => $alightStop['lng'] ?? null,
            ],
        ];

        if (!empty($coordinates)) {
            $payload['coordinates'] = $coordinates;
        }

        if ($polyline !== null) {
            $payload['polyline'] = $polyline;
        }

        try {
            $request = Http::timeout(max(0.1, $this->timeout))->acceptJson();

            if ($this->apiKey) {
                $request = $request->withToken($this->apiKey);
            }

            $response = $request->post($url, $payload);

            if ($response->successful()) {
                $minutes = $this->extractMinutes($response->json());

                if ($minutes !== null) {
                    return [
                        'minutes' => max(0.0, $minutes),
                        'source' => 'bus_time_api',
                    ];
                }

                Log::warning('Bus travel time API returned unexpected response', [
                    'payload' => $response->json(),
                ]);
            } else {
                Log::warning('Bus travel time API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Bus travel time API call threw exception', [
                'error' => $e->getMessage(),
            ]);
        }

        return $fallback('Falling back to heuristic after API failure');
    }

    /**
     * Fetch traffic data for a list of route legs.
     * Only processes the top N candidates to save costs.
     */
    public function enrichWithTraffic(array $legs): array
    {
        if (!$this->mapboxAccessToken) {
            return $legs;
        }

        $today = Carbon::now()->format('Y-m-d');
        $rateLimitKey = "mapbox_calls_{$today}";

        foreach ($legs as &$leg) {
            // Only enrich bus legs that have coordinates
            if (($leg['mode'] ?? '') !== 'bus' || empty($leg['coordinates'])) {
                continue;
            }

            $cacheKey = 'traffic_' . md5(json_encode($leg['coordinates']));

            // 1. Check Cache
            $cached = Cache::get($cacheKey);
            if ($cached) {
                $leg['traffic'] = $cached;
                continue;
            }

            // 2. Check Rate Limit
            $currentCalls = Cache::get($rateLimitKey, 0);
            if ($currentCalls >= $this->dailyLimit) {
                $leg['traffic'] = ['level' => 'unknown', 'reason' => 'limit_reached'];
                continue;
            }

            // 3. Fetch from Mapbox
            $trafficData = $this->fetchMapboxTraffic($leg['coordinates']);

            if ($trafficData) {
                // Cache for 15 minutes
                Cache::put($cacheKey, $trafficData, now()->addMinutes(15));

                // Increment rate limit counter (expires in 24h)
                if (!Cache::has($rateLimitKey)) {
                    Cache::put($rateLimitKey, 1, 86400);
                } else {
                    Cache::increment($rateLimitKey);
                }

                $leg['traffic'] = $trafficData;
            }
        }

        return $legs;
    }

    private function fetchMapboxTraffic(array $coordinates): ?array
    {
        try {
            // Mapbox Directions API requires "longitude,latitude" format separated by semicolons
            // We'll sample coordinates to avoid URL length limits (e.g., take start, middle, end, and some intermediates)
            $sampled = $this->sampleCoordinates($coordinates, 20);
            $waypoints = implode(';', array_map(fn($c) => "{$c[1]},{$c[0]}", $sampled));

            $url = "https://api.mapbox.com/directions/v5/mapbox/driving-traffic/{$waypoints}";

            $response = Http::get($url, [
                'access_token' => $this->mapboxAccessToken,
                'overview' => 'false',
                'steps' => 'false',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data['routes'][0])) {
                    $route = $data['routes'][0];
                    $duration = $route['duration']; // seconds (traffic adjusted)
                    $distance = $route['distance']; // meters

                    // Calculate "typical" duration (assuming ~40km/h or 11.1 m/s free flow if not provided)
                    // Or better: Mapbox doesn't give "typical" in standard response without specific params, 
                    // but we can infer traffic level from speed.

                    // Simple heuristic: 
                    // Speed < 15 km/h (4.1 m/s) => Heavy
                    // Speed < 30 km/h (8.3 m/s) => Moderate
                    // Else => Low

                    $speedMps = $duration > 0 ? $distance / $duration : 10;
                    $speedKmph = $speedMps * 3.6;

                    $level = 'low';
                    if ($speedKmph < 15)
                        $level = 'heavy';
                    elseif ($speedKmph < 30)
                        $level = 'moderate';

                    return [
                        'level' => $level,
                        'duration_seconds' => $duration,
                        'speed_kmph' => round($speedKmph, 1),
                        'source' => 'mapbox'
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error('Mapbox Traffic API Error: ' . $e->getMessage());
        }

        return null;
    }

    private function sampleCoordinates(array $coords, int $limit): array
    {
        $count = count($coords);
        if ($count <= $limit)
            return $coords;

        $step = $count / ($limit - 1);
        $sampled = [];

        for ($i = 0; $i < $limit - 1; $i++) {
            $sampled[] = $coords[(int) ($i * $step)];
        }
        $sampled[] = $coords[$count - 1]; // Always include destination

        return $sampled;
    }

    private function extractMinutes($payload): ?float
    {
        if (!is_array($payload)) {
            return null;
        }

        $candidates = [
            Arr::get($payload, 'minutes'),
            Arr::get($payload, 'duration_minutes'),
            Arr::get($payload, 'data.minutes'),
            Arr::get($payload, 'data.duration_minutes'),
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
