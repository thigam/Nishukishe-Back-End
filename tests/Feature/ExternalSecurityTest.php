<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class ExternalSecurityTest extends TestCase
{
    /**
     * Test that staging backend endpoints are secure and do not return successful data (200 OK) to guest scrapers.
     */
    public function test_external_staging_endpoints_are_secure(): void
    {
        $endpoints = [
            'https://api-staging.nishukishe.com/routes',
            'https://api-staging.nishukishe.com/stops',
            'https://api-staging.nishukishe.com/direction',
            'https://api-staging.nishukishe.com/sacco',
        ];

        foreach ($endpoints as $url) {
            try {
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'Nishukishe-Security-Test-Suite/1.0',
                ])->timeout(5)->get($url);

                // If the response is 200 OK, it means data is leaking!
                // Any of 401 (Unauthenticated), 403 (Forbidden), or 429 (Rate Limited) are acceptable secure states.
                $this->assertNotEquals(
                    200,
                    $response->status(),
                    "Staging endpoint {$url} is publicly leaking data (returned HTTP 200)!"
                );
            } catch (\Exception $e) {
                if ($e instanceof AssertionFailedError) {
                    throw $e;
                }
                $this->markTestSkipped("Staging is unreachable: " . $e->getMessage());
            }
        }
    }

    /**
     * Test that production backend endpoints are secure and do not return successful data (200 OK) to guest scrapers.
     */
    public function test_external_production_endpoints_are_secure(): void
    {
        $endpoints = [
            'https://backend.nishukishe.com/routes',
            'https://backend.nishukishe.com/stops',
            'https://backend.nishukishe.com/direction',
            'https://backend.nishukishe.com/sacco',
        ];

        foreach ($endpoints as $url) {
            try {
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'Nishukishe-Security-Test-Suite/1.0',
                ])->timeout(5)->get($url);

                // If the response is 200 OK, it means data is leaking!
                // Any of 401 (Unauthenticated), 403 (Forbidden), or 429 (Rate Limited) are acceptable secure states.
                $this->assertNotEquals(
                    200,
                    $response->status(),
                    "Production endpoint {$url} is publicly leaking data (returned HTTP 200)!"
                );
            } catch (\Exception $e) {
                if ($e instanceof AssertionFailedError) {
                    throw $e;
                }
                $this->markTestSkipped("Production is unreachable: " . $e->getMessage());
            }
        }
    }
}
