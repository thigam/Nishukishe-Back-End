<?php

namespace App\Services\Scalping\Drivers;

use App\Services\Scalping\Contracts\ScalpingDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BuuPassDriver implements ScalpingDriver
{
    protected string $baseUrl = 'https://marketplace-v2.buupass.com/marketplace';
    protected string $apiKey = '66Shf2aP.owT6xw6QKSWNO9EeQIIlz20JM3nNHul3'; // Should be in config/env

    public function getProviderName(): string
    {
        return 'buupass';
    }

    protected function getBookingChannel(): ?string
    {
        return null;
    }

    public function search(string $origin, string $destination, string $date): array
    {
        $url = "{$this->baseUrl}/buses/";

        try {
            $queryParams = [
                'leaving_from' => $origin,
                'going_to' => $destination,
                'departing_on' => $date
            ];

            if ($this->getBookingChannel()) {
                $queryParams['booking_channel'] = $this->getBookingChannel();
            }

            $response = Http::withHeaders([
                'Authorization' => 'Api-Key ' . $this->apiKey,
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ])->get($url, $queryParams);

            if ($response->failed()) {
                Log::error("BuuPass API Error ({$this->getProviderName()}): " . $response->body());
                return [];
            }

            $data = $response->json();
            // Log::info("BuuPass Raw Response: " . substr($response->body(), 0, 500)); // Log snippet
            return $this->parseResults($data, $origin, $destination, $date);

        } catch (\Exception $e) {
            Log::error("BuuPass Driver Exception ({$this->getProviderName()}): " . $e->getMessage());
            return [];
        }
    }

    protected function parseResults(array $data, $origin, $destination, $date): array
    {
        $results = [];
        if (!isset($data['data']['schedule']) || !is_array($data['data']['schedule'])) {
            return [];
        }

        foreach ($data['data']['schedule'] as $bus) {
            // Extract price from seat types (take the lowest non-zero price)
            $price = 0;
            if (isset($bus['seats_types']) && is_array($bus['seats_types'])) {
                foreach ($bus['seats_types'] as $type) {
                    if (isset($type['fare']) && $type['fare'] > 0) {
                        if ($price == 0 || $type['fare'] < $price) {
                            $price = $type['fare'];
                        }
                    }
                }
            }

            // Construct Deep Link
            // Corrected Deep Link based on user feedback
            // https://buupass.com/buses/Mombasa-to-Nairobi?fromCityName=Mombasa&toCityName=Nairobi&onward=2026-01-03
            $deepLink = "https://buupass.com/buses/" . urlencode("{$origin}-to-{$destination}") .
                "?fromCityName=" . urlencode($origin) .
                "&toCityName=" . urlencode($destination) .
                "&onward={$date}";

            // Fix Date Logic
            $departureTimeStr = $bus['departure_time'] ?? '00:00:00';
            $arrivalTimeStr = $bus['arrival_time'] ?? null;

            // Ensure departure is on the requested date
            $departure = \Carbon\Carbon::parse($date . ' ' . $departureTimeStr);

            $arrival = null;
            if ($arrivalTimeStr) {
                // Try to parse arrival. It might be just time, or date+time.
                // If it's just time, Carbon::parse($time) uses today's date.
                // We should check if it contains a date separator like '-' or '/'.
                $hasDate = strpos($arrivalTimeStr, '-') !== false || strpos($arrivalTimeStr, '/') !== false;

                if ($hasDate) {
                    $arrival = \Carbon\Carbon::parse($arrivalTimeStr);
                } else {
                    // Just time, assume same day as departure initially
                    $arrival = \Carbon\Carbon::parse($date . ' ' . $arrivalTimeStr);
                }

                // If arrival is before departure, it's likely next day (overnight)
                // OR the date component was wrong (e.g. BuuPass returned a default date)
                if ($arrival->lt($departure)) {
                    // If it was just time, we assume next day
                    if (!$hasDate) {
                        $arrival->addDay();
                    } else {
                        // If it had a date and is still before departure, the date is probably wrong.
                        // We'll reset the date to departure date and check time again.
                        $timeOnly = $arrival->format('H:i:s');
                        $arrival = \Carbon\Carbon::parse($date . ' ' . $timeOnly);
                        if ($arrival->lt($departure)) {
                            $arrival->addDay();
                        }
                    }
                }
            }

            $results[] = [
                'provider' => $this->getProviderName(),
                'operator_name' => $bus['operator']['name'] ?? 'Unknown',
                'operator_logo' => $bus['operator']['logo'] ?? null,
                'origin' => $bus['from'] ?? $origin,
                'destination' => $bus['to'] ?? $destination,
                'departure_time' => $departure->format('Y-m-d H:i:s'),
                'arrival_time' => $arrival ? $arrival->format('Y-m-d H:i:s') : null,
                'price' => $price,
                'currency' => 'KES',
                'available_seats' => $bus['number_of_available_seats'] ?? 0,
                'deep_link' => $deepLink,
                'raw_data' => $bus
            ];
        }

        return $results;
    }
}
