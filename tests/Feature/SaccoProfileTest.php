<?php

namespace Tests\Feature;

use App\Models\Sacco;
use App\Models\SaccoManager;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaccoProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_sacco_registration_stores_profile_fields(): void
    {
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'phone' => '+254700000001',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'is_verified' => true,
            'is_approved' => true,
        ]);

        Sanctum::actingAs($superAdmin, ['*']);
        $this->actingAs($superAdmin, 'sanctum');

        $payload = [
            'sacco_name' => 'Discover City Movers',
            'sacco_location' => 'Nairobi',
            'sacco_phone' => '+254700123456',
            'sacco_email' => 'discover@example.com',
            'sacco_website' => 'https://discover.example.com',
            'sacco_logo' => 'logos/discover.png',
            'vehicle_type' => 'Matatu',
            'till_number' => '123456',
            'paybill_number' => '654321',
            'profile_headline' => 'Ride in comfort across the city',
            'profile_description' => 'We provide safe and reliable transport solutions for all city commuters.',
            'share_slug' => 'discover-city-movers',
            'profile_contact_name' => 'Jane Doe',
            'profile_contact_phone' => '+254701234567',
            'profile_contact_email' => 'contact@discover.example.com',
        ];

        $response = $this->postJson('/sacco/create', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('sacco.profile_headline', 'Ride in comfort across the city')
            ->assertJsonPath('sacco.share_slug', 'discover-city-movers');

        $saccoId = $response->json('sacco.sacco_id');
        $this->assertNotNull($saccoId);

        $retrieved = $this->getJson('/sacco/' . $saccoId);
        $retrieved->assertStatus(200)
            ->assertJsonFragment([
                'profile_headline' => 'Ride in comfort across the city',
                'profile_description' => 'We provide safe and reliable transport solutions for all city commuters.',
                'share_slug' => 'discover-city-movers',
                'profile_contact_email' => 'contact@discover.example.com',
            ]);
    }

    public function test_sacco_admin_can_update_own_profile_and_retrieve_changes(): void
    {
        $sacco = Sacco::create([
            'sacco_id' => 'SC0001',
            'sacco_name' => 'Metro Movers',
            'vehicle_type' => 'Matatu',
            'join_date' => Carbon::now(),
            'sacco_logo' => null,
            'sacco_location' => 'Nairobi',
            'sacco_phone' => '+254799000111',
            'sacco_email' => 'metro@example.com',
            'sacco_website' => 'https://metro.example.com',
            'till_number' => '700111',
            'paybill_number' => '110007',
            'is_approved' => true,
            'profile_headline' => 'We move the metro',
            'profile_description' => 'Initial description',
            'share_slug' => 'metro-movers',
            'profile_contact_name' => 'Initial Contact',
            'profile_contact_phone' => '+254700000000',
            'profile_contact_email' => 'initial@metro.example.com',
        ]);

        $saccoAdmin = User::create([
            'name' => 'Metro Admin',
            'email' => 'metro@example.com',
            'phone' => '+254700000002',
            'password' => bcrypt('password'),
            'role' => UserRole::SACCO,
            'is_verified' => true,
            'is_approved' => true,
        ]);

        Sanctum::actingAs($saccoAdmin, ['*']);
        $this->actingAs($saccoAdmin, 'sanctum');

        $updatePayload = [
            'profile_headline' => 'Metro rides, reimagined',
            'profile_description' => 'Updated description for the metro sacco.',
            'share_slug' => 'metro-rides',
            'profile_contact_name' => 'John Metro',
            'profile_contact_phone' => '+254711222333',
            'profile_contact_email' => 'hello@metro.example.com',
        ];

        $response = $this->putJson('/sacco/' . $sacco->sacco_id . '/profile', $updatePayload);

        $response->assertStatus(200)
            ->assertJsonPath('sacco.profile_headline', 'Metro rides, reimagined')
            ->assertJsonPath('sacco.share_slug', 'metro-rides');

        $retrieved = $this->getJson('/sacco/' . $sacco->sacco_id);
        $retrieved->assertStatus(200)
            ->assertJsonFragment([
                'profile_headline' => 'Metro rides, reimagined',
                'profile_description' => 'Updated description for the metro sacco.',
                'share_slug' => 'metro-rides',
                'profile_contact_email' => 'hello@metro.example.com',
            ]);
    }

    public function test_sacco_manager_associated_with_sacco_can_update_profile(): void
    {
        $sacco = Sacco::create([
            'sacco_id' => 'SC0002',
            'sacco_name' => 'Galaxy Riders',
            'vehicle_type' => 'Matatu',
            'join_date' => Carbon::now(),
            'sacco_logo' => null,
            'sacco_location' => 'Nairobi',
            'sacco_phone' => '+254799000112',
            'sacco_email' => 'info@galaxy.example.com',
            'sacco_website' => 'https://galaxy.example.com',
            'till_number' => '700112',
            'paybill_number' => '110008',
            'is_approved' => true,
            'profile_headline' => 'Across the galaxy',
            'profile_description' => 'Initial description',
            'share_slug' => 'galaxy-riders',
            'profile_contact_name' => 'Initial Contact',
            'profile_contact_phone' => '+254700000111',
            'profile_contact_email' => 'initial@galaxy.example.com',
        ]);

        $saccoManager = User::create([
            'name' => 'Galaxy Manager',
            'email' => 'manager@galaxy.example.com',
            'phone' => '+254700000003',
            'password' => bcrypt('password'),
            'role' => UserRole::SACCO,
            'is_verified' => true,
            'is_approved' => true,
        ]);

        SaccoManager::create([
            'user_id' => $saccoManager->id,
            'sacco_id' => $sacco->sacco_id,
        ]);

        Sanctum::actingAs($saccoManager, ['*']);
        $this->actingAs($saccoManager, 'sanctum');

        $updatePayload = [
            'profile_headline' => 'Galaxy rides, reimagined',
            'profile_description' => 'Updated description for the galaxy sacco.',
            'share_slug' => 'galaxy-rides',
            'profile_contact_name' => 'Grace Galaxy',
            'profile_contact_phone' => '+254722333444',
            'profile_contact_email' => 'hello@galaxy.example.com',
        ];

        $response = $this->putJson('/sacco/' . $sacco->sacco_id . '/profile', $updatePayload);

        $response->assertStatus(200)
            ->assertJsonPath('sacco.profile_headline', 'Galaxy rides, reimagined')
            ->assertJsonPath('sacco.share_slug', 'galaxy-rides');

        $retrieved = $this->getJson('/sacco/' . $sacco->sacco_id);
        $retrieved->assertStatus(200)
            ->assertJsonFragment([
                'profile_headline' => 'Galaxy rides, reimagined',
                'profile_description' => 'Updated description for the galaxy sacco.',
                'share_slug' => 'galaxy-rides',
                'profile_contact_email' => 'hello@galaxy.example.com',
            ]);
    }
}
