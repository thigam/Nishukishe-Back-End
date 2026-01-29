<?php

namespace App\Services\Scalping\Drivers;

class MashPoaDriver extends IabiriDriver
{
    public function getProviderName(): string
    {
        return 'mashpoa';
    }

    protected function parseResults(array $data, $origin, $destination, $date): array
    {
        $results = parent::parseResults($data, $origin, $destination, $date);

        // Filter for Mash Poa if necessary, though generic Iabiri driver might return all.
        // If we want to be specific, we can filter by operator name.
        // For now, we'll return all, but we could add:
        /*
        return array_filter($results, function ($res) {
            return stripos($res['operator_name'], 'mash') !== false;
        });
        */

        // Add deep link specific to Mash Poa if possible
        foreach ($results as &$res) {
            // https://mashpoa.com/
            // Mash Poa site is SPA, deep linking might be tricky without specific route params.
            // But we can link to the search page.
            $res['deep_link'] = "https://mashpoa.com/";
        }

        return $results;
    }
}
