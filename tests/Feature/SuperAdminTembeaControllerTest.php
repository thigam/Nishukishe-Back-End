<?php

namespace Tests\Feature;

use App\Models\TembeaOperatorProfile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SuperAdminTembeaControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function createSuperAdmin()
    {
        return User::factory()->create([
            'role' => UserRole::SUPER_ADMIN,
        ]);
    }

    public function test_super_admin_can_create_placeholder_operator()
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin)->postJson(route('superadmin.tembea.operators.placeholder'), [
            'company_name' => 'Safari Adventures',
            'contact_name' => 'John Doe',
            'contact_phone' => '254700000000',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'operator']);

        $this->assertDatabaseHas('tembea_operator_profiles', [
            'company_name' => 'Safari Adventures',
            'contact_name' => 'John Doe',
            'slug' => 'safari-adventures',
        ]);

        $profile = TembeaOperatorProfile::where('slug', 'safari-adventures')->first();
        $this->assertTrue(str_ends_with($profile->contact_email, '@placeholder.local'));
        $this->assertTrue($profile->user->is_verified);
    }

    public function test_super_admin_can_change_operator_email()
    {
        $admin = $this->createSuperAdmin();

        // Create placeholder first
        $this->actingAs($admin)->postJson(route('superadmin.tembea.operators.placeholder'), [
            'company_name' => 'Test Operator',
            'contact_name' => 'Test Contact',
            'contact_phone' => '0700000000',
        ])->assertStatus(201);

        $profile = TembeaOperatorProfile::where('company_name', 'Test Operator')->first();
        $oldEmail = $profile->user->email;

        $newEmail = 'real@example.com';

        $response = $this->actingAs($admin)->postJson(route('superadmin.tembea.operators.email', $profile->id), [
            'email' => $newEmail,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $profile->user_id,
            'email' => $newEmail,
        ]);

        $profile->refresh();
        $this->assertEquals($newEmail, $profile->contact_email);
        $this->assertArrayNotHasKey('is_placeholder', $profile->metadata ?? []);

        // Ensure old email is gone from user
        $this->assertDatabaseMissing('users', ['email' => $oldEmail]);
    }

    public function test_super_admin_can_list_operators()
    {
        $admin = $this->createSuperAdmin();

        TembeaOperatorProfile::factory()->count(3)->create();

        $response = $this->actingAs($admin)->getJson(route('superadmin.tembea.operators.index'));

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page', 'total']);
    }
}
