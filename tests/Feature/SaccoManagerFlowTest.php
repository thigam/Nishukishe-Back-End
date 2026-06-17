<?php

namespace Tests\Feature;

use App\Models\Sacco;
use App\Models\SaccoStage;
use App\Models\User;
use App\Models\UserRole;
use App\Events\UserRegistered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SaccoManagerFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sacco_manager_flow(): void
    {
        // 1. Signup
        Event::fake([UserRegistered::class]);

        $signupPayload = [
            'sacco_name' => 'Horizon Transport',
            'sacco_location' => 'Nairobi',
            'sacco_phone' => '+254700000001',
            'sacco_email' => 'horizon@example.com',
            'vehicle_type' => 'Bus',
        ];

        $response = $this->postJson('/sacco/create', $signupPayload);
        $response->assertStatus(201);

        $saccoId = $response->json('sacco.sacco_id');
        $user = User::where('email', 'horizon@example.com')->firstOrFail();
        $user->permissions()->createMany([
            ['permission' => 'manage_stages'],
            ['permission' => 'manage_routes'],
        ]);

        Event::assertDispatched(UserRegistered::class);

        // 2. Email verification
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(5),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->getJson($verificationUrl)->assertStatus(200);
        $this->assertTrue($user->refresh()->is_verified);

        // 3. Login and profile update
        $user->update(['is_approved' => true, 'password' => bcrypt('password123')]);
        
        $tier = \App\Models\SaccoTier::create([
            'name' => 'Premium',
            'price' => 0.00,
            'is_active' => true,
            'features' => ['route_creation' => true],
        ]);

        Sacco::where('sacco_id', $saccoId)->update([
            'is_approved' => true,
            'tier_id' => $tier->id,
        ]);

        \App\Models\SaccoManager::create([
            'user_id' => $user->id,
            'sacco_id' => $saccoId,
        ]);

        $this->postJson('/auth/login', [
            'email' => 'horizon@example.com',
            'password' => 'password123',
            'role' => UserRole::SACCO,
        ])->assertStatus(200);

        $this->putJson("/sacco/" . $saccoId . "/profile", [
            'profile_headline' => 'The best view of the horizon',
            'profile_description' => 'Comfortable rides guaranteed.',
            'share_slug' => 'horizon-bus',
        ])->assertStatus(200);

        // 4. Stage creation and slug fetch
        $this->actingAs($user, 'sanctum');

        $res = $this->postJson("/sacco/" . $saccoId . "/stages", [
            'name' => 'Nairobi CBD Terminus',
            'latitude' => -1.286389,
            'longitude' => 36.817223,
        ]);
        $res->assertStatus(201);

        $this->getJson("/sacco/horizon-bus/stages")
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Nairobi CBD Terminus']);

        // 5. Route submission and cleanup
        $stage = SaccoStage::where('sacco_id', $saccoId)->firstOrFail();

        $routeResponse = $this->postJson("/routes/add", [
            'sacco_id' => $saccoId,
            'route_number' => '102',
            'route_id' => 'nairobi-thika-102',
            'route_start_stop' => 'Nairobi CBD Terminus',
            'route_end_stop' => 'Thika',
            'stop_ids' => ['stop_1', 'stop_2'],
            'coordinates' => [[36.8, -1.2], [36.9, -1.1]],
            'peak_fare' => 150,
            'off_peak_fare' => 100,
            'currency' => 'KES',
            'county_id' => 1,
            'mode' => 'bus',
            'waiting_time' => 10,
        ]);

        $routeResponse->assertStatus(201);
        $routeId = $routeResponse->json('route_id');

        \App\Models\PostCleanSaccoRoute::create([
            'pre_clean_id' => $routeResponse->json('id'),
            'sacco_id' => $saccoId,
            'route_id' => $routeId,
            'sacco_route_id' => $routeResponse->json('sacco_route_id'),
            'route_number' => '102',
            'route_start_stop' => 'Nairobi CBD Terminus',
            'route_end_stop' => 'Thika',
            'direction_index' => 1,
            'coordinates' => [[36.8, -1.2], [36.9, -1.1]],
            'stop_ids' => ['stop_1', 'stop_2'],
        ]);

        $this->postJson("/routes/{$routeId}/request-cleanup", [
            'sacco_id' => $saccoId,
            'notes' => 'Fix it please',
        ])->assertSuccessful();
    }
}
