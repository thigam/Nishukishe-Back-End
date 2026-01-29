<?php

namespace App\Services;

use Carbon\Carbon;

class FareCalculator
{
    /**
     * Distance tier configuration for sacco fares.
     *
     * Tiers are expressed as percentages of the full route distance so that a
     * traveler covering half of the route pays roughly half of the sacco-
     * provided fare. When the sacco fare is missing, the legacy fallback
     * amounts are used directly (without applying the fractions) to preserve
     * previous defaults.
     */
    private const DISTANCE_PERCENTAGE_TIERS = [
        [
            'limit_ratio' => 0.25,
            'off_peak_fraction' => 0.5,
            'peak_fraction' => 0.6,
            'fallback_off_peak' => 40.0,
            'fallback_peak' => 60.0,
        ],
        [
            'limit_ratio' => 0.50,
            'off_peak_fraction' => 0.7,
            'peak_fraction' => 0.8,
            'fallback_off_peak' => 60.0,
            'fallback_peak' => 80.0,
        ],
        [
            'limit_ratio' => 0.75,
            'off_peak_fraction' => 0.9,
            'peak_fraction' => 1.0,
            'fallback_off_peak' => 80.0,
            'fallback_peak' => 100.0,
        ],
        [
            'limit_ratio' => 1.0,
            'off_peak_fraction' => 1.0,
            'peak_fraction' => 1.0,
            'fallback_off_peak' => 110.0,
            'fallback_peak' => 130.0,
        ],
    ];

    /**
     * Fallback manual-distance guard when the total route length is unknown.
     */
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
        bool $alightingInCbd = false
    ): array {
        $distanceKm = max(0.0, $distanceKm);
        $distanceRatio = $this->distanceRatio($distanceKm, $totalDistanceKm);
        $tier = $this->matchTierByRatio($distanceRatio);
        $requiresManual = $this->requiresManualFare($distanceRatio, $distanceKm, $totalDistanceKm);

        $offPeak = $this->fractionalFare($tier, $storedOffPeakFare, 'off_peak_fraction', 'fallback_off_peak');
        $peakBase = $storedPeakFare ?? $storedOffPeakFare;
        $peak = $this->fractionalFare($tier, $peakBase, 'peak_fraction', 'fallback_peak');

        $offPeak = $this->roundToNearestTen($offPeak);
        $peak = $this->roundUpToNearestTen($peak);

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

    private function fractionalFare(array $tier, ?float $baseFare, string $fractionKey, string $fallbackKey): float
    {
        if ($baseFare === null) {
            return $tier[$fallbackKey];
        }

        $fraction = $tier[$fractionKey];

        return $baseFare * $fraction;
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

    private function matchTierByRatio(float $distanceRatio): array
    {
        foreach (self::DISTANCE_PERCENTAGE_TIERS as $tier) {
            if ($distanceRatio <= $tier['limit_ratio']) {
                return $tier;
            }
        }

        return self::DISTANCE_PERCENTAGE_TIERS[array_key_last(self::DISTANCE_PERCENTAGE_TIERS)];
    }

    private function distanceRatio(float $distanceKm, ?float $totalDistanceKm): float
    {
        if ($totalDistanceKm !== null && $totalDistanceKm > 0.0) {
            return $distanceKm / $totalDistanceKm;
        }

        // If the total route length is unknown, assume 100% of the route so the
        // fare tiers fall back to the upper bound instead of undercharging.
        return 1.0;
    }

    private function requiresManualFare(float $distanceRatio, float $distanceKm, ?float $totalDistanceKm): bool
    {
        if ($distanceRatio > $this->maxTierRatio()) {
            return true;
        }

        // Retain the legacy manual flag when the total route distance is
        // unknown and the trip exceeds the previous hard distance guard.
        if ($totalDistanceKm === null) {
            return $distanceKm > self::ABSOLUTE_MANUAL_DISTANCE_KM;
        }

        return false;
    }

    private function maxTierRatio(): float
    {
        return self::DISTANCE_PERCENTAGE_TIERS[array_key_last(self::DISTANCE_PERCENTAGE_TIERS)]['limit_ratio'];
    }
}
