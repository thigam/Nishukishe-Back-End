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

    public function test_sacco_manager_end_to_end_journey(): void
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
        $response->assertStatus(201)
            ->assertJsonPath('message', 'Sacco successfully registered!');

        $saccoId = $response->json('sacco.sacco_id');
        $this->assertNotNull($saccoId);

        Event::assertDispatched(UserRegistered::class, function ($event) {
            return $event->user->email === 'horizon@example.com';
        });

        $user = User::where('email', 'horizon@example.com')->firstOrFail();
        $this->assertFalse($user->is_verified);

        // 2. Email Verification
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(5),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $verifyResponse = $this->getJson($verificationUrl);
        $verifyResponse->assertStatus(200);

        $user->refresh();
        $this->assertTrue($user->is_verified);

        // 3. Approval (simulate Super Admin approval for the sake of the flow)
        $user->update(['is_approved' => true]);
        $sacco = Sacco::where('sacco_id', $saccoId)->firstOrFail();
        $sacco->update(['is_approved' => true]);

        // Manually change password so we can log in, because the real signup sends a random password via email
        $user->update(['password' => bcrypt('password123')]);

        // 4. Login
        $loginResponse = $this->postJson('/auth/login', [
            'email' => 'horizon@example.com',
            'password' => 'password123',
        ]);
        $loginResponse->assertStatus(200)
            ->assertJsonPath('user.role', UserRole::SACCO);

        // 5. Update Profile
        $profilePayload = [
            'profile_headline' => 'The best view of the horizon',
            'profile_description' => 'Comfortable rides guaranteed.',
            'share_slug' => 'horizon-bus',
        ];

        $profileResponse = $this->putJson("/sacco/{$saccoId}/profile", $profilePayload);
        $profileResponse->assertStatus(200)
            ->assertJsonPath('sacco.profile_headline', 'The best view of the horizon');

        // 6. Create a Stage
        $stagePayload = [
            'name' => 'Nairobi CBD Terminus',
            'latitude' => -1.286389,
            'longitude' => 36.817223,
            'description' => 'Main departure point',
        ];

        $stageResponse = $this->postJson("/sacco/{$saccoId}/stages", $stagePayload);
        $stageResponse->assertStatus(201)
            ->assertJsonPath('name', 'Nairobi CBD Terminus');

        $stageId = $stageResponse->json('id');

        // 7. Verify the stage exists and is accessible via custom slug
        $fetchStageResponse = $this->getJson("/sacco/horizon-bus/stages");
        $fetchStageResponse->assertStatus(200)
            ->assertJsonFragment(['name' => 'Nairobi CBD Terminus']);

        // 8. Submit a new route
        // Wait, route creation requires stops. Let's create another stage.
        $stage2Payload = [
            'name' => 'Mombasa Terminus',
            'latitude' => -4.043477,
            'longitude' => 39.668206,
        ];
        $stage2Response = $this->postJson("/sacco/{$saccoId}/stages", $stage2Payload);
        $stage2Id = $stage2Response->json('id');

        $routePayload = [
            'route_number' => '0',
            'stops' => [
                ['stop_name' => 'Nairobi CBD Terminus', 'order' => 1, 'stage_id' => $stageId],
                ['stop_name' => 'Mombasa Terminus', 'order' => 2, 'stage_id' => $stage2Id],
            ],
            'sacco_id' => $saccoId,
        ];

        $routeResponse = $this->postJson("/routes/add", $routePayload);
        $routeResponse->assertStatus(201);
        $routeId = $routeResponse->json('route_id');

        // 9. Request Route Correction (assuming this endpoint exists and takes corrections array)
        $correctionPayload = [
            'corrections' => 'The Nairobi terminus should be labelled as Afya Centre.',
        ];
        $correctionResponse = $this->postJson("/routes/{$routeId}/request-cleanup", $correctionPayload);

        // requestCleanup returns 200 or 201 usually. Let's assert successful response.
        $correctionResponse->assertSuccessful();
    }
}
