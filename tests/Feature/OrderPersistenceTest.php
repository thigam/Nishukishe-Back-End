<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PreCleanSaccoRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stop_order_preservation()
    {
        // Create an authenticating Super Admin user
        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'phone' => '+254700000000',
            'password' => bcrypt('password'),
            'role' => \App\Models\UserRole::SUPER_ADMIN,
            'is_approved' => true,
            'is_verified' => true,
        ]);

        // 1. Create a dummy PreClean Route
        $saccoRouteId = 'TEST_ORDER_001';
        PreCleanSaccoRoute::create([
            'sacco_id' => 'TEST_SACCO',
            'route_id' => 'TEST_ROUTE_ID',
            'sacco_route_id' => $saccoRouteId,
            'route_start_stop' => 'A',
            'route_end_stop' => 'B',
            'stop_ids' => [1, 2, 3],
            'coordinates' => [],
            'status' => 'pending'
        ]);

        // 2. Simulate API Call to Update with REVERSED order
        $payload = [
            'sacco_id' => 'TEST_SACCO',
            'route_id' => 'TEST_ROUTE_ID',
            'sacco_route_id' => $saccoRouteId, // Critical: Passing ID to trigger upsert
            'route_start_stop' => 'A',
            'route_end_stop' => 'B',
            'stop_ids' => [3, 2, 1], // Reversed!
            'coordinates' => [],
            'status' => 'pending'
        ];

        $response = $this->actingAs($user)->postJson('/pre-clean/routes', $payload);

        // Assert update is successful (200 OK)
        $response->assertStatus(200);

        // 3. Verify order preservation in Database
        $updated = PreCleanSaccoRoute::where('sacco_route_id', $saccoRouteId)->firstOrFail();
        $this->assertEquals([3, 2, 1], $updated->stop_ids);
    }
}
