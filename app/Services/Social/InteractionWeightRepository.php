<?php

namespace App\Services\Social;

use App\Models\SocialMetricWeight;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class InteractionWeightRepository
{
    private const CACHE_KEY = 'social_metric_weights';
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(private readonly CacheRepository $cache)
    {
    }

    /**
     * Retrieve weight mapping for a given platform. Falls back to defaults from config.
     *
     * @return array<string, float>
     */
    public function weightsForPlatform(string $platform): array
    {
        $platform = strtolower($platform);
        $allWeights = $this->allWeights();
        $platformWeights = $allWeights[$platform] ?? [];

        $defaults = config('social.default_weights', []);
        $platformDefaults = config("social.platform_weights.{$platform}", []);

        return Collection::make($defaults)
            ->merge($platformDefaults)
            ->merge($platformWeights)
            ->map(fn ($value) => is_numeric($value) ? (float) $value : 0.0)
            ->all();
    }

    /**
     * @return array<string, array<string, float>>
     */
    public function allWeights(): array
    {
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return SocialMetricWeight::query()
                ->get()
                ->groupBy(fn (SocialMetricWeight $weight) => strtolower($weight->platform))
                ->map(function ($weights) {
                    return $weights
                        ->mapWithKeys(fn (SocialMetricWeight $weight) => [strtolower($weight->metric) => (float) $weight->weight])
                        ->all();
                })
                ->map(fn (array $weights) => Arr::where($weights, fn ($value) => is_numeric($value)))
                ->all();
        });
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
