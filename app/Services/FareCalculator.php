<?php

namespace App\Services;

use Carbon\Carbon;

class FareCalculator
{
    private const ABSOLUTE_MANUAL_DISTANCE_KM = 45.0;

    private const CBD_POLYGON = [
        [-1.2836, 36.8177],
        [-1.2878, 36.8219],
        [-1.2897, 36.8319],
        [-1.2864, 36.8346],
        [-1.2779, 36.8277],
        [-1.2799, 36.8203],
        [-1.2836, 36.8177],
    ];

    public function calculate(
        float $distanceKm,
        ?float $totalDistanceKm = null,
        ?Carbon $departureTime = null,
        bool $isEventDay = false,
        ?float $storedPeakFare = null,
        ?float $storedOffPeakFare = null,
        bool $boardingInCbd = false,
        bool $alightingInCbd = false,
        bool $isFirstStop = false
    ): array {
        $distanceKm = max(0.0, $distanceKm);

        // Scenario 1: Sacco route has no fare or total distance is unknown
        if (($storedOffPeakFare === null && $storedPeakFare === null) || $totalDistanceKm === null || $totalDistanceKm <= 0.0) {
            return [
                'fare' => 0.0,
                'peak_fare' => 0.0,
                'off_peak_fare' => 0.0,
                'distance_km' => round($distanceKm, 2),
                'requires_manual_fare' => true, // Triggers "Ask conductor"
                'is_peak_fare' => false,
            ];
        }

        // Scenario 2: Calculate linear fare (If boarding at first stop, 100% fare applies)
        $distanceRatio = $isFirstStop ? 1.0 : ($distanceKm / $totalDistanceKm);
        $requiresManual = ($distanceKm > self::ABSOLUTE_MANUAL_DISTANCE_KM || $distanceRatio > 1.0);

        // Cap ratio at 100% of route
        $cappedRatio = min(1.0, max(0.0, $distanceRatio));

        // Use off peak as the base if either is missing
        $baseOffPeak = $storedOffPeakFare ?? $storedPeakFare;
        $basePeak = $storedPeakFare ?? $storedOffPeakFare;

        $offPeak = $cappedRatio * $baseOffPeak;
        $peak = $cappedRatio * $basePeak;

        // Round off-peak to nearest 10, peak rounded up to nearest 10
        $offPeak = $this->roundToNearestTen($offPeak);
        $peak = $this->roundUpToNearestTen($peak);

        // Ensure minimum fare is 20 KES
        $offPeak = max(20.0, $offPeak);
        $peak = max(20.0, $peak);

        if ($peak < $offPeak) {
            $peak = $offPeak;
        }

        $fare = $offPeak;
        $usePeak = $isEventDay || $this->shouldUsePeakFare($departureTime, $boardingInCbd, $alightingInCbd);
        if ($usePeak) {
            $fare = $peak;
        }

        return [
            'fare' => $fare,
            'peak_fare' => $peak,
            'off_peak_fare' => $offPeak,
            'distance_km' => round($distanceKm, 2),
            'requires_manual_fare' => $requiresManual,
            'is_peak_fare' => $usePeak,
        ];
    }

    public function isInCbd(float $lat, float $lng): bool
    {
        return $this->pointInPolygon($lat, $lng, self::CBD_POLYGON);
    }

    public function distanceBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(max(1 - $a, 0.0)));

        return $earthRadiusKm * $c;
    }

    private function shouldUsePeakFare(?Carbon $departureTime, bool $boardingInCbd, bool $alightingInCbd): bool
    {
        if (!$departureTime) {
            return false;
        }

        $minutes = ((int) $departureTime->format('H')) * 60 + (int) $departureTime->format('i');

        $morningStart = 5 * 60 + 30;
        $morningEnd = 9 * 60 + 30;
        $eveningStart = 16 * 60;
        $eveningEnd = 20 * 60;

        $withinPeakWindow = ($minutes >= $morningStart && $minutes <= $morningEnd)
            || ($minutes >= $eveningStart && $minutes <= $eveningEnd);

        if ($withinPeakWindow) {
            return true;
        }

        $cbdDirectionalPeak = (!$boardingInCbd && $alightingInCbd && $minutes >= $morningStart && $minutes <= $morningEnd)
            || ($boardingInCbd && !$alightingInCbd && $minutes >= $eveningStart && $minutes <= $eveningEnd);

        return $cbdDirectionalPeak;
    }

    private function roundToNearestTen(float $value): float
    {
        return round($value / 10.0) * 10.0;
    }

    private function roundUpToNearestTen(float $value): float
    {
        return ceil($value / 10.0) * 10.0;
    }

    private function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$latI, $lngI] = $polygon[$i];
            [$latJ, $lngJ] = $polygon[$j];

            $intersects = (($latI > $lat) !== ($latJ > $lat)) &&
                ($lng < ($lngJ - $lngI) * ($lat - $latI) / (($latJ - $latI) ?: 1e-9) + $lngI);

            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
