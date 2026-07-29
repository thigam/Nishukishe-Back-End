<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class WalkRouter
{
    /**
     * Request-lifetime memory cache.
     * Prevents duplicate calls to OSRM/ORS within a single search request.
     * This is garbage-collected at the end of each request, so it doesn't grow unboundedly.
     */
    private array $memoryCache = [];

    public function __construct(
        private ?string $base = null,
        private ?string $apiKey = null
    ) {
        $this->base = rtrim($base ?? config('walk.base_url', 'https://api.openrouteservice.org'), '/');
        $this->apiKey = $apiKey ?? config('walk.ors_api_key');
    }

    /**
     * Get a walking route between two points.
     * Checks request-lifetime memory cache first, then a 7-day persistent cache (Redis/DB),
     * then calls OSRM or ORS, then falls back to a straight-line estimate.
     *
     * Straight-line fallback results are NOT cached — they are temporary and should be
     * re-fetched once the routing server is available again.
     *
     * @return array|null ['coords'=>[[lat,lng]...], 'distance_m'=>int, 'duration_s'=>int, 'steps'=>[]]
     */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        // Round to 5 decimal places (~1 m precision) to maximise cache hits
        $fromLat = round($fromLat, 5);
        $fromLng = round($fromLng, 5);
        $toLat   = round($toLat, 5);
        $toLng   = round($toLng, 5);

        $cacheKey = "walk_route:{$fromLat},{$fromLng}:{$toLat},{$toLng}";

        // 1. Request-lifetime memory cache
        if (array_key_exists($cacheKey, $this->memoryCache)) {
            return $this->memoryCache[$cacheKey];
        }

        // 2. Persistent cache (Redis preferred, falls back to DB/file cache)
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            $this->memoryCache[$cacheKey] = $cached;
            return $cached;
        }

        // 3. Perform the actual HTTP call
        $result = $this->performRoute($fromLat, $fromLng, $toLat, $toLng);

        // 4. Only cache a real routing result, NOT a straight-line fallback.
        //    Fallback results have exactly 2 coordinates; real routes have more.
        $isFallback = ($result !== null && count($result['coords'] ?? []) === 2);

        if ($result !== null && !$isFallback) {
            // Cache for 7 days — walking routes between fixed stops don't change
            Cache::put($cacheKey, $result, now()->addDays(7));
            $this->memoryCache[$cacheKey] = $result;
        } else {
            // Still store in memory cache to avoid duplicate API calls during one request,
            // but don't persist — we want to retry the server on the next request.
            $this->memoryCache[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * Perform the actual HTTP routing call (OSRM or ORS) with straight-line fallback.
     */
    private function performRoute(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        $isOsrm = !str_contains($this->base, 'openrouteservice.org');

        if ($isOsrm) {
            // OSRM GET endpoint: /route/v1/walking/{lng1},{lat1};{lng2},{lat2}
            $coords = "{$fromLng},{$fromLat};{$toLng},{$toLat}";
            $url = "{$this->base}/{$coords}?overview=full&geometries=geojson";

            try {
                $res = Http::timeout(0.5)->acceptJson()->get($url);

                if ($res->ok()) {
                    $route = $res->json('routes.0');
                    $coordsLatLng = array_map(
                        fn($p) => [(float) $p[1], (float) $p[0]],
                        $route['geometry']['coordinates'] ?? []
                    );

                    return [
                        'coords'     => $coordsLatLng,
                        'distance_m' => (int) round($route['distance'] ?? 0),
                        'duration_s' => (int) round($route['duration'] ?? 0),
                        'steps'      => [],
                    ];
                }
            } catch (\Throwable $e) {
                \Log::warning('OSRM walk route exception', ['error' => $e->getMessage()]);
            }
        } else {
            // ORS POST endpoint
            $url  = "{$this->base}/v2/directions/foot-walking/geojson";
            $body = [
                'coordinates' => [
                    [$fromLng, $fromLat],
                    [$toLng, $toLat],
                ],
                'instructions' => false,
            ];

            try {
                $res = Http::timeout(1)
                    ->acceptJson()
                    ->withHeaders([
                        'Authorization' => $this->apiKey,
                        'Content-Type'  => 'application/json',
                    ])
                    ->post($url, $body);

                if ($res->ok()) {
                    $feat    = $res->json('features.0');
                    $summary = $feat['properties']['summary'] ?? null;
                    $coords  = $feat['geometry']['coordinates'] ?? null;

                    if ($summary && is_array($coords)) {
                        return [
                            'coords'     => array_map(fn($p) => [(float) $p[1], (float) $p[0]], $coords),
                            'distance_m' => (int) round($summary['distance'] ?? 0),
                            'duration_s' => (int) round($summary['duration'] ?? 0),
                            'steps'      => [],
                        ];
                    }
                }

                \Log::warning('ORS walk route failed', [
                    'status' => $res->status(),
                    'body'   => $res->body(),
                    'req'    => $body,
                ]);
            } catch (\Throwable $e) {
                \Log::warning('ORS walk route exception', ['error' => $e->getMessage()]);
            }
        }

        // Straight-line fallback (NOT cached persistently)
        $km        = self::haversineKm($fromLat, $fromLng, $toLat, $toLng);
        $distanceM = (int) round($km * 1000);
        $durationS = (int) round(($km / 4.8) * 3600);

        return [
            'coords'     => [[$fromLat, $fromLng], [$toLat, $toLng]],
            'distance_m' => $distanceM,
            'duration_s' => $durationS,
            'steps'      => [],
        ];
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371; // km
        $lat1  = deg2rad($lat1);
        $lat2  = deg2rad($lat2);
        $dLat  = $lat2 - $lat1;
        $dLng  = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth * $c;
    }
}
