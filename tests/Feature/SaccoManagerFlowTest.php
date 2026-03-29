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

    private static string $saccoId;
    private static int $userId;

    public function test_01_signup(): void
    {
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

        self::$saccoId = $response->json('sacco.sacco_id');
        $user = User::where('email', 'horizon@example.com')->firstOrFail();
        self::$userId = $user->id;

        Event::assertDispatched(UserRegistered::class);
    }

    public function test_02_email_verification(): void
    {
        $user = User::findOrFail(self::$userId);
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(5),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->getJson($verificationUrl)->assertStatus(200);
        $this->assertTrue($user->refresh()->is_verified);
    }

    public function test_03_login_and_profile_update(): void
    {
        $user = User::findOrFail(self::$userId);
        $user->update(['is_approved' => true, 'password' => bcrypt('password123')]);
        Sacco::where('sacco_id', self::$saccoId)->update(['is_approved' => true]);

        $this->postJson('/auth/login', [
            'email' => 'horizon@example.com',
            'password' => 'password123',
        ])->assertStatus(200);

        $this->putJson("/sacco/" . self::$saccoId . "/profile", [
            'profile_headline' => 'The best view of the horizon',
            'profile_description' => 'Comfortable rides guaranteed.',
            'share_slug' => 'horizon-bus',
        ])->assertStatus(200);
    }

    public function test_04_stage_creation_and_slug_fetch(): void
    {
        $user = User::findOrFail(self::$userId);
        $this->actingAs($user, 'sanctum');

        $this->postJson("/sacco/" . self::$saccoId . "/stages", [
            'name' => 'Nairobi CBD Terminus',
            'latitude' => -1.286389,
            'longitude' => 36.817223,
        ])->assertStatus(201);

        $this->getJson("/sacco/horizon-bus/stages")
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Nairobi CBD Terminus']);
    }

    public function test_05_route_submission_and_cleanup(): void
    {
        $user = User::findOrFail(self::$userId);
        $this->actingAs($user, 'sanctum');

        $stage = SaccoStage::where('sacco_id', self::$saccoId)->firstOrFail();

        $routeResponse = $this->postJson("/routes/add", [
            'route_number' => '0',
            'stops' => [
                ['stop_name' => 'Nairobi CBD Terminus', 'order' => 1, 'stage_id' => $stage->id],
                ['stop_name' => 'End', 'order' => 2, 'latitude' => -1.3, 'longitude' => 36.8],
            ],
            'sacco_id' => self::$saccoId,
        ]);

        $routeResponse->assertStatus(201);
        $routeId = $routeResponse->json('route_id');

        $this->postJson("/routes/{$routeId}/request-cleanup", [
            'corrections' => 'Fix it please',
        ])->assertSuccessful();
    }
}
