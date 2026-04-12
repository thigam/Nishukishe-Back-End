<?php

namespace Tests\Feature;

use App\Models\Sacco;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductWaitlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_waitlist_store_route_accessible_without_api_prefix(): void
    {
        $sacco = Sacco::factory()->create(['sacco_id' => 'TEST001']);
        $user = User::factory()->create();

        $payload = [
            'sacco_id' => $sacco->sacco_id,
            'product_slug' => 'parcels-management',
            'contact_name' => 'Test User',
            'contact_email' => 'test@example.com',
        ];

        // This should hit the route in WaitlistRoutes.php (included in web.php)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/waitlist', $payload);

        $response->assertStatus(201)
            ->assertJsonFragment(['product_slug' => 'parcels-management']);
    }

    public function test_admin_waitlist_route_accessible_without_api_prefix(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        // This should hit the route in WaitlistRoutes.php (included in web.php)
        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/admin/waitlist');

        $response->assertStatus(200);
    }

    public function test_waitlist_store_requires_auth(): void
    {
        $response = $this->postJson('/waitlist', []);

        // Should redirect or return 401 depending on middleware. 
        // In web.php it might redirect to login if not 'api' middleware.
        // But we kept 'auth:sanctum' which usually returns 401 for JSON requests.
        $response->assertStatus(401);
    }
}
