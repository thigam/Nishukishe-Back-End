<?php

namespace App\Services\Social;

class InteractionScorer
{
    public function __construct(private readonly InteractionWeightRepository $weightRepository)
    {
    }

    /**
     * Calculate a weighted interaction score for a set of metrics.
     *
     * @param  array<string, int|float|null>  $metrics
     */
    public function score(string $platform, array $metrics): float
    {
        $weights = $this->weightRepository->weightsForPlatform($platform);

        $score = 0.0;
        foreach ($metrics as $key => $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $weight = $weights[strtolower($key)] ?? 0.0;
            $score += (float) $value * (float) $weight;
        }

        return round($score, 4);
    }
}
