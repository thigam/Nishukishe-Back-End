<?php

namespace Tests\Feature;

use App\Http\Middleware\LogUserActivity;
use App\Models\SocialMetric;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SocialMetricControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('social_metrics');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('social_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('platform');
            $table->string('metric_type');
            $table->decimal('value', 15, 4);
            $table->timestamp('recorded_at');
            $table->string('post_identifier')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function test_requires_authentication_for_social_metrics_routes(): void
    {
        $response = $this->getJson('/superadmin/social-metrics');
        $response->assertStatus(401);

        $response = $this->postJson('/superadmin/social-metrics', []);
        $response->assertStatus(401);
    }

    public function test_non_super_admin_is_forbidden_from_accessing_social_metrics(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $user = User::create([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'phone' => '254700000010',
            'password' => bcrypt('password'),
            'role' => UserRole::USER,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/superadmin/social-metrics');
        $response->assertStatus(403);
    }

    public function test_super_admin_can_store_and_update_social_metrics(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $this->authenticateSuperAdmin();

        $payload = [
            'platform' => 'instagram',
            'metric_type' => 'followers',
            'value' => 1024,
            'recorded_at' => '2024-01-05T12:00:00Z',
            'post_identifier' => 'post-123',
            'metadata' => ['note' => 'manual entry'],
        ];

        $response = $this->postJson('/superadmin/social-metrics', $payload);
        $response->assertCreated();
        $response->assertJsonPath('data.platform', 'instagram');
        $this->assertEquals(1, SocialMetric::count());

        $metricId = $response->json('data.id');

        $updatePayload = [
            'platform' => 'instagram',
            'metric_type' => 'followers',
            'value' => 2048,
            'recorded_at' => '2024-01-06T12:00:00Z',
            'post_identifier' => 'post-123',
            'metadata' => ['note' => 'adjusted'],
        ];

        $updateResponse = $this->putJson("/superadmin/social-metrics/{$metricId}", $updatePayload);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.value', 2048);
        $this->assertEquals('adjusted', $updateResponse->json('data.metadata.note'));
    }

    public function test_store_validates_required_fields(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $this->authenticateSuperAdmin();

        $response = $this->postJson('/superadmin/social-metrics', [
            'platform' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['platform', 'metric_type', 'value', 'recorded_at']);
    }

    public function test_index_filters_by_platform_and_date_range(): void
    {
        $this->withoutMiddleware([LogUserActivity::class]);

        $this->authenticateSuperAdmin();

        SocialMetric::create([
            'platform' => 'instagram',
            'metric_type' => 'followers',
            'value' => 1200,
            'recorded_at' => Carbon::parse('2024-01-01T09:00:00Z'),
            'metadata' => [],
        ]);

        SocialMetric::create([
            'platform' => 'instagram',
            'metric_type' => 'followers',
            'value' => 1250,
            'recorded_at' => Carbon::parse('2024-01-03T09:00:00Z'),
            'metadata' => [],
        ]);

        SocialMetric::create([
            'platform' => 'tiktok',
            'metric_type' => 'views',
            'value' => 500,
            'recorded_at' => Carbon::parse('2024-01-02T09:00:00Z'),
            'metadata' => [],
        ]);

        $response = $this->getJson('/superadmin/social-metrics?platform=instagram&start=2024-01-01&end=2024-01-02');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.platform', 'instagram');
        $response->assertJsonMissing(['platform' => 'tiktok']);
    }

    private function authenticateSuperAdmin(): User
    {
        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'phone' => '254700000001',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }
}
