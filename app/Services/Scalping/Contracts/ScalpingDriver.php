<?php

namespace App\Services\Scalping\Contracts;

interface ScalpingDriver
{
    /**
     * Search for trips on the external platform.
     *
     * @param string $origin (City Name)
     * @param string $destination (City Name)
     * @param string $date (YYYY-MM-DD)
     * @return array List of standardized trip results
     */
    public function search(string $origin, string $destination, string $date): array;

    /**
     * Get the unique identifier for this driver.
     */
    public function getProviderName(): string;
}
