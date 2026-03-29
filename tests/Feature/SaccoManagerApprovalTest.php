<?php

namespace Tests\Feature;

use App\Models\Sacco;
use App\Models\SaccoManager;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class SaccoManagerApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_person_with_permission_can_list_sacco_managers()
    {
        $servicePerson = User::factory()->create(['role' => UserRole::SERVICE_PERSON]);
        $servicePerson->permissions()->create(['permission' => 'manage_sacco_managers']);

        $sacco = Sacco::factory()->create(['sacco_id' => 'sacco_1']);
        // Create an unapproved manager so they are visible
        $managerUser = User::factory()->create(['role' => UserRole::SACCO, 'is_approved' => false]);
        SaccoManager::create(['user_id' => $managerUser->id, 'sacco_id' => 'sacco_1']);

        Sanctum::actingAs($servicePerson);

        $response = $this->getJson('/api/admin/sacco-managers');

        $response->assertStatus(200);
        $response->assertJsonFragment(['email' => $managerUser->email, 'sacco_name' => $sacco->sacco_name]);
    }

    public function test_service_person_without_permission_cannot_list_sacco_managers()
    {
        $servicePerson = User::factory()->create(['role' => UserRole::SERVICE_PERSON]);

        Sanctum::actingAs($servicePerson);

        $response = $this->getJson('/api/admin/sacco-managers');

        $response->assertStatus(403);
    }

    public function test_service_person_can_approve_sacco_manager()
    {
        $servicePerson = User::factory()->create(['role' => UserRole::SERVICE_PERSON]);
        $servicePerson->permissions()->create(['permission' => 'manage_sacco_managers']);

        $managerUser = User::factory()->create([
            'role' => UserRole::SACCO,
            'is_approved' => false,
            'is_verified' => false
        ]);

        Sanctum::actingAs($servicePerson);

        $response = $this->postJson("/api/admin/sacco-managers/{$managerUser->id}/approve");

        $response->assertStatus(200);
        $this->assertTrue($managerUser->refresh()->is_approved);
        $this->assertTrue($managerUser->is_verified);
        $response->assertJsonStructure(['message', 'temp_password']);
    }

    public function test_service_person_can_manually_verify_sacco_manager()
    {
        $servicePerson = User::factory()->create(['role' => UserRole::SERVICE_PERSON]);
        $servicePerson->permissions()->create(['permission' => 'manage_sacco_managers']);

        $managerUser = User::factory()->create([
            'role' => UserRole::SACCO,
            'is_verified' => false
        ]);

        Sanctum::actingAs($servicePerson);

        $response = $this->postJson("/api/admin/sacco-managers/{$managerUser->id}/manual-verify");

        $response->assertStatus(200);
        $this->assertTrue($managerUser->refresh()->is_verified);
    }

    public function test_service_person_only_sees_relevant_sacco_managers()
    {
        $servicePerson = User::factory()->create(['role' => UserRole::SERVICE_PERSON]);
        $servicePerson->permissions()->create(['permission' => 'manage_sacco_managers']);

        $sacco = Sacco::factory()->create(['sacco_id' => 'sacco_1']);

        // 1. Pending (unapproved) - should see
        $pendingUser = User::factory()->create(['role' => UserRole::SACCO, 'is_approved' => false, 'email' => 'pending@example.com']);
        SaccoManager::create(['user_id' => $pendingUser->id, 'sacco_id' => 'sacco_1']);

        // 2. Unverified - should see
        $unverifiedUser = User::factory()->create(['role' => UserRole::SACCO, 'is_approved' => true, 'is_verified' => false, 'email' => 'unverified@example.com']);
        SaccoManager::create(['user_id' => $unverifiedUser->id, 'sacco_id' => 'sacco_1']);

        // 3. Recently verified (<72h) - should see
        $recentUser = User::factory()->create([
            'role' => UserRole::SACCO,
            'is_approved' => true,
            'is_verified' => true,
            'email_verified_at' => now()->subHours(10),
            'email' => 'recent@example.com'
        ]);
        SaccoManager::create(['user_id' => $recentUser->id, 'sacco_id' => 'sacco_1']);

        // 4. Old verified (>72h) - should NOT see
        $oldUser = User::factory()->create([
            'role' => UserRole::SACCO,
            'is_approved' => true,
            'is_verified' => true,
            'email_verified_at' => now()->subHours(80),
            'email' => 'old@example.com'
        ]);
        SaccoManager::create(['user_id' => $oldUser->id, 'sacco_id' => 'sacco_1']);

        Sanctum::actingAs($servicePerson);

        $response = $this->getJson('/api/admin/sacco-managers');

        $response->assertStatus(200);
        $response->assertJsonFragment(['email' => 'pending@example.com']);
        $response->assertJsonFragment(['email' => 'unverified@example.com']);
        $response->assertJsonFragment(['email' => 'recent@example.com']);
        $response->assertJsonMissing(['email' => 'old@example.com']);
    }
}
