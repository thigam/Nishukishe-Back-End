<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WalkRouter
{
    public function __construct(
        private ?string $base = null,
        private ?string $apiKey = null
    ) {
        $this->base = rtrim($base ?? config('walk.base_url', 'https://api.openrouteservice.org'), '/');
        $this->apiKey = $apiKey ?? config('walk.ors_api_key');
    }

    /**
     * Get a walking route between two points via ORS; falls back to straight line if it fails.
     * @return array|null ['coords'=>[[lat,lng]...], 'distance_m'=>int, 'duration_s'=>int, 'steps'=>[]]
     */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        $isOsrm = false; // str_contains($this->base, '127.0.0.1'); 

        // FORCE FALLBACK FOR DEMO (Skip HTTP calls)
        if (true) {
            // fallback straight line
            $km = self::haversineKm($fromLat, $fromLng, $toLat, $toLng);
            $distanceM = (int) round($km * 1000);
            $durationS = (int) round(($km / 4.8) * 3600);

            return [
                'coords' => [[$fromLat, $fromLng], [$toLat, $toLng]],
                'distance_m' => $distanceM,
                'duration_s' => $durationS,
                'steps' => [],
            ];
        }

        if ($isOsrm) {
            // OSRM expects v1 GET path
            $coords = "{$fromLng},{$fromLat};{$toLng},{$toLat}";
            $url = "{$this->base}/{$coords}?overview=full&geometries=geojson";

            try {
                $res = Http::timeout(8)->acceptJson()->get($url);

                if ($res->ok()) {
                    $route = $res->json('routes.0');
                    $coordsLatLng = array_map(
                        fn($p) => [(float) $p[1], (float) $p[0]],
                        $route['geometry']['coordinates'] ?? []
                    );

                    return [
                        'coords' => $coordsLatLng,
                        'distance_m' => (int) round($route['distance'] ?? 0),
                        'duration_s' => (int) round($route['duration'] ?? 0),
                        'steps' => [], // keep interface consistent
                    ];
                }
            } catch (\Throwable $e) {
                \Log::warning('OSRM walk route exception', ['error' => $e->getMessage()]);
            }
        } else {
            // ORS behavior unchanged
            $url = "{$this->base}/v2/directions/foot-walking/geojson";
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
                        'Content-Type' => 'application/json',
                    ])
                    ->post($url, $body);

                if ($res->ok()) {
                    $feat = $res->json('features.0');
                    $summary = $feat['properties']['summary'] ?? null;
                    $coords = $feat['geometry']['coordinates'] ?? null;

                    if ($summary && is_array($coords)) {
                        return [
                            'coords' => array_map(fn($p) => [(float) $p[1], (float) $p[0]], $coords),
                            'distance_m' => (int) round($summary['distance'] ?? 0),
                            'duration_s' => (int) round($summary['duration'] ?? 0),
                            'steps' => [],
                        ];
                    }
                }

                \Log::warning('ORS walk route failed', [
                    'status' => $res->status(),
                    'body' => $res->body(),
                    'req' => $body,
                ]);
            } catch (\Throwable $e) {
                \Log::warning('ORS walk route exception', ['error' => $e->getMessage()]);
            }
        }

        // fallback straight line
        $km = self::haversineKm($fromLat, $fromLng, $toLat, $toLng);
        $distanceM = (int) round($km * 1000);
        $durationS = (int) round(($km / 4.8) * 3600);

        return [
            'coords' => [[$fromLat, $fromLng], [$toLat, $toLng]],
            'distance_m' => $distanceM,
            'duration_s' => $durationS,
            'steps' => [],
        ];
    }
    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371; // km
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth * $c;
    }
}

