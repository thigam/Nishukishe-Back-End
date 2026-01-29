<?php

namespace App\Services\Scalping;

use App\Services\Scalping\Contracts\ScalpingDriver;
use Illuminate\Support\Facades\Log;

class ScalpingService
{
    protected array $drivers = [];

    public function __construct(
        \App\Services\Scalping\Drivers\BuuPassDriver $buupass,
        \App\Services\Scalping\Drivers\EasyCoachDriver $easycoach,
        \App\Services\Scalping\Drivers\IabiriDriver $iabiri,
        \App\Services\Scalping\Drivers\MashPoaDriver $mashpoa
    ) {
        $this->registerDriver($buupass);
        $this->registerDriver($easycoach);
        $this->registerDriver($iabiri);
        $this->registerDriver($mashpoa);
    }

    public function registerDriver(ScalpingDriver $driver): void
    {
        $this->drivers[$driver->getProviderName()] = $driver;
    }

    public function searchAll(string $origin, string $destination, string $date): array
    {
        $results = [];

        foreach ($this->drivers as $name => $driver) {
            try {
                $driverResults = $driver->search($origin, $destination, $date);
                $results = array_merge($results, $driverResults);
            } catch (\Exception $e) {
                Log::error("Scalping failed for driver {$name}: " . $e->getMessage());
            }
        }

        return $this->processResults($results);
    }

    public function searchProvider(string $provider, string $origin, string $destination, string $date): array
    {
        $driver = $this->getDriver($provider);
        if (!$driver) {
            return [];
        }

        try {
            $results = $driver->search($origin, $destination, $date);
            return $this->processResults($results);
        } catch (\Exception $e) {
            Log::error("Scalping failed for driver {$provider}: " . $e->getMessage());
            return [];
        }
    }

    protected function processResults(array $results): array
    {
        // Auto-map results to Saccos
        $saccos = \App\Models\Sacco::pluck('sacco_name', 'sacco_id')->toArray();

        foreach ($results as &$result) {
            $match = $this->resolveSaccoId($result['operator_name'], $saccos);
            $result['suggested_sacco_id'] = $match['id'] ?? null;
            $result['match_confidence'] = $match['confidence'] ?? 0;
        }

        return $results;
    }

    protected function resolveSaccoId(string $operatorName, array $saccos): ?array
    {
        $bestMatch = null;
        $highestSimilarity = 0;

        foreach ($saccos as $id => $name) {
            similar_text(strtolower($operatorName), strtolower($name), $percent);
            if ($percent > $highestSimilarity) {
                $highestSimilarity = $percent;
                $bestMatch = $id;
            }
        }

        // Threshold: 80% similarity
        if ($highestSimilarity >= 80) {
            return ['id' => $bestMatch, 'confidence' => $highestSimilarity];
        }

        return null;
    }

    public function getDriver(string $name): ?ScalpingDriver
    {
        return $this->drivers[$name] ?? null;
    }
}
