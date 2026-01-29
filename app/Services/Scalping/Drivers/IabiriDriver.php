<?php

namespace App\Services\Scalping\Drivers;

use App\Services\Scalping\Contracts\ScalpingDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class IabiriDriver implements ScalpingDriver
{
    protected string $baseUrl = 'https://api.iabiri.com/appApiV1';
    protected string $authToken = '4F5D3QC5-C94A-CFD5-87C1-4E2903311DF0';

    public function getProviderName(): string
    {
        return 'iabiri';
    }

    public function search(string $origin, string $destination, string $date): array
    {
        $originId = $this->getCityId($origin);
        $destinationId = $this->getCityId($destination);

        if (!$originId || !$destinationId) {
            Log::warning("Iabiri Driver: Could not resolve city IDs for {$origin} -> {$destination}");
            return [];
        }

        $url = "{$this->baseUrl}/booking/filterBuses";

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->authToken,
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Content-Type' => 'application/json'
            ])->post($url, [
                        'from_city_id' => $originId,
                        'to_city_id' => $destinationId,
                        'travel_date' => $date
                    ]);

            if ($response->failed()) {
                Log::error("Iabiri API Error ({$this->getProviderName()}): " . $response->body());
                return [];
            }

            $data = $response->json();
            return $this->parseResults($data, $origin, $destination, $date);

        } catch (\Exception $e) {
            Log::error("Iabiri Driver Exception ({$this->getProviderName()}): " . $e->getMessage());
            return [];
        }
    }

    protected function getCityId(string $cityName): ?int
    {
        $cities = Cache::remember('iabiri_cities', 86400, function () {
            $response = Http::withHeaders([
                'Authorization' => $this->authToken,
                'User-Agent' => 'Mozilla/5.0'
            ])->post("{$this->baseUrl}/common/getCity", []);

            if ($response->failed()) {
                Log::error("Iabiri Failed to fetch cities: " . $response->body());
                return [];
            }

            return $response->json()['data'] ?? [];
        });

        foreach ($cities as $city) {
            if (strcasecmp($city['name'], $cityName) === 0) {
                return $city['id'];
            }
        }

        // Try partial match if exact match fails
        foreach ($cities as $city) {
            if (stripos($city['name'], $cityName) !== false) {
                return $city['id'];
            }
        }

        return null;
    }

    protected function parseResults(array $data, $origin, $destination, $date): array
    {
        $results = [];
        if (!isset($data['data']) || !is_array($data['data'])) {
            return [];
        }

        foreach ($data['data'] as $bus) {
            // Price logic: usually in 'fare' or 'price'
            $price = $bus['fare'] ?? $bus['price'] ?? 0;

            // Deep Link (Generic Iabiri or specific provider site if known)
            // For now, we'll point to the generic site or just return null if unknown
            $deepLink = null;

            $departureTime = $bus['departure_time'] ?? '00:00:00';
            $arrivalTime = $bus['arrival_time'] ?? null;

            // Ensure departure is on the requested date
            $departure = \Carbon\Carbon::parse($date . ' ' . $departureTime);

            $arrival = null;
            if ($arrivalTime) {
                $arrival = \Carbon\Carbon::parse($date . ' ' . $arrivalTime);
                if ($arrival->lt($departure)) {
                    $arrival->addDay();
                }
            }

            $results[] = [
                'provider' => $this->getProviderName(),
                'operator_name' => $bus['company_name'] ?? $bus['operator_name'] ?? 'Unknown',
                'operator_logo' => $bus['company_logo'] ?? null,
                'origin' => $origin, // Use searched origin as API returns IDs
                'destination' => $destination,
                'departure_time' => $departure->format('Y-m-d H:i:s'),
                'arrival_time' => $arrival ? $arrival->format('Y-m-d H:i:s') : null,
                'price' => $price,
                'currency' => 'KES',
                'available_seats' => $bus['available_seats'] ?? 0,
                'deep_link' => $deepLink,
                'raw_data' => $bus
            ];
        }

        return $results;
    }
}
