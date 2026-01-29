<?php

namespace App\Services\Social\Providers;

use Carbon\CarbonInterface;

interface SocialPlatformProvider
{
    public function platform(): string;

    /**
     * Fetch the latest metrics snapshot for the platform.
     *
     * @return array{
     *     collected_at: CarbonInterface|string,
     *     account: array<string, mixed>,
     *     metrics: array<string, int|float|null>,
     *     posts?: array<int, array<string, mixed>>
     * }
     */
    public function fetchSnapshot(CarbonInterface $since): array;
}
