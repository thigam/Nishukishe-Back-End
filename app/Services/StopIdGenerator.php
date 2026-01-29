<?php

namespace App\Services;

class StopIdGenerator
{
    private const PREFIX = 'ST';
    private const SCALE = 100000;
    private const MAX_DIGITS = 7;
    private const MAX_VALUE = 9999999;

    public function generate(float $latitude, float $longitude): string
    {
        [$latHemisphere, $latValue] = $this->formatCoordinate($latitude, 'N', 'S');
        [$lngHemisphere, $lngValue] = $this->formatCoordinate($longitude, 'E', 'W');

        return sprintf('%s_%s%s_%s%s', self::PREFIX, $latHemisphere, $latValue, $lngHemisphere, $lngValue);
    }

    private function formatCoordinate(float $value, string $positiveHemisphere, string $negativeHemisphere): array
    {
        $hemisphere = $value < 0 ? $negativeHemisphere : $positiveHemisphere;
        $scaled = (int) round(abs($value) * self::SCALE);
        $scaled = min($scaled, self::MAX_VALUE);
        $digits = str_pad((string) $scaled, self::MAX_DIGITS, '0', STR_PAD_LEFT);

        return [$hemisphere, $digits];
    }
}
