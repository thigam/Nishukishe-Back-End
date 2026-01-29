<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class PlaceholderSaccoCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
    }

    public function test_placeholder_sacco_creation_generates_unique_credentials(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\CorsMiddleware::class,
            \App\Http\Middleware\RoleMiddleware::class,
            \App\Http\Middleware\LogUserActivity::class,
        ]);

        $payload = [
            'sacco_name' => 'Placeholder Transit',
            'sacco_location' => 'Nairobi',
            'vehicle_type' => 'bus',
        ];

        $firstResponse = $this->postJson('/sacco/placeholder', $payload);
        $firstResponse->assertCreated();
        $firstSacco = $firstResponse->json('sacco');

        $secondResponse = $this->postJson('/sacco/placeholder', $payload);
        $secondResponse->assertCreated();
        $secondSacco = $secondResponse->json('sacco');

        $this->assertNotSame($firstSacco['sacco_email'], $secondSacco['sacco_email']);
        $this->assertNotSame($firstSacco['sacco_phone'], $secondSacco['sacco_phone']);
        $this->assertTrue($firstSacco['is_approved']);
        $this->assertTrue($secondSacco['is_approved']);

        $this->assertArrayNotHasKey('is_placeholder', $firstSacco);
        $this->assertArrayNotHasKey('is_placeholder', $secondSacco);

        $this->assertDatabaseHas('saccos', [
            'sacco_id' => $firstSacco['sacco_id'],
            'sacco_email' => $firstSacco['sacco_email'],
        ]);
    }
}
