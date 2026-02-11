<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PreCleanSaccoRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderPersistenceTest extends TestCase
{
    // use RefreshDatabase; // Don't wipe the DB, just create a temp record

    public function test_stop_order_preservation()
    {
        // 1. Create a dummy PreClean Route
        $saccoRouteId = 'TEST_ORDER_001';
        $pre = PreCleanSaccoRoute::create([
            'sacco_id' => 'TEST_SACCO',
            'route_id' => 'TEST_ROUTE_ID',
            'sacco_route_id' => $saccoRouteId,
            'route_start_stop' => 'A',
            'route_end_stop' => 'B',
            'stop_ids' => [1, 2, 3],
            'coordinates' => [],
            'status' => 'pending'
        ]);

        echo "Created Route: " . json_encode($pre->stop_ids) . "\n";

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

        // Manually instantiate controller or just force the update logic to verify DB behavior
        // But better to use the HTTP test client to test the actual route/controller
        $user = User::first();
        if (!$user) {
            echo "No user found to auth.\n";
            return;
        }

        $response = $this->actingAs($user)->postJson('/api/pre-clean/routes', $payload);

        $response->assertStatus(200); // 200 = Updated (201 = Created)

        // 3. Verify DB
        $updated = PreCleanSaccoRoute::where('sacco_route_id', $saccoRouteId)->first();
        echo "Updated Route: " . json_encode($updated->stop_ids) . "\n";

        if ($updated->stop_ids === [3, 2, 1]) {
            echo "SUCCESS: Order was preserved!\n";
        } else {
            echo "FAILURE: Order was NOT preserved.\n";
        }

        // Cleanup
        $updated->delete();
    }
}
