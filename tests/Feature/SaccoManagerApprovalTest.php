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
        $managerUser = User::factory()->create(['role' => UserRole::SACCO]);
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
}
