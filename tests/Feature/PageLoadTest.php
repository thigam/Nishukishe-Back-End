<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserRole;

class PageLoadTest extends TestCase
{
    /**
     * Test that critical public pages load correctly.
     */
    public function test_public_pages_load()
    {
        $pages = [
            '/',
            // '/login', // POST only
            // '/register', // POST only
        ];

        foreach ($pages as $page) {
            $response = $this->get($page);
            $response->assertStatus(200);
        }
    }

    /**
     * Test that authenticated dashboard pages load.
     */
    public function test_dashboard_pages_load()
    {
        // Create a user for testing
        $user = User::factory()->create([
            'role' => UserRole::SUPER_ADMIN,
        ]);

        // Check an actual existing GET route for superadmin
        $response = $this->actingAs($user)->get('/superadmin/analytics');
        $response->assertStatus(200);
    }
}
