<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminLogsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('activity_logs');
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
            $table->timestamp('email_verified_at')->nullable();
            $table->string('remember_token')->nullable();
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

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('session_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('device')->nullable();
            $table->string('browser')->nullable();
            $table->json('urls_visited')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->json('routes_searched')->nullable();
            $table->timestamps();
        });
    }

    public function test_super_admin_can_view_activity_logs(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\LogUserActivity::class,
        ]);

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super.admin@example.com',
            'phone' => '254700000000',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        Sanctum::actingAs($superAdmin, ['*']);
        $this->actingAs($superAdmin, 'sanctum');

        $actor = User::create([
            'name' => 'Active User',
            'email' => 'active.user@example.com',
            'phone' => '254700000001',
            'password' => bcrypt('password'),
            'role' => UserRole::USER,
            'is_verified' => true,
            'is_active' => true,
            'is_approved' => true,
        ]);

        $startedAt = Carbon::create(2024, 1, 1, 10, 0, 0, 'UTC');
        $endedAt = Carbon::create(2024, 1, 1, 10, 10, 0, 'UTC');

        ActivityLog::create([
            'user_id' => $actor->id,
            'session_id' => 'session-123',
            'ip_address' => '127.0.0.1',
            'device' => 'Desktop',
            'browser' => 'Chrome',
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $endedAt->diffInSeconds($startedAt),
            'urls_visited' => ['/dashboard', '/reports'],
            'routes_searched' => [[
                'origin' => [-1.286389, 36.817223],
                'destination' => [-1.264906, 36.814758],
                'origin_label' => 'CBD',
                'destination_label' => 'Westlands',
                'include_walking' => false,
                'searched_at' => '2024-01-01T10:05:00+00:00',
                'has_results' => true,
                'route_summaries' => [
                    [
                        'type' => 'multi_leg',
                        'legs' => 2,
                        'first_leg' => [
                            'mode' => 'bus',
                            'route_id' => 'R1',
                            'sacco_name' => 'City Hopper',
                            'route_number' => '23',
                        ],
                        'total_duration_minutes' => 35,
                    ],
                ],
            ]],
        ]);

        $response = $this->getJson('/superadmin/logs');

        $response->assertOk();

        $response->assertJsonStructure([
            'data' => [
                [
                    'id',
                    'session_id',
                    'ip_address',
                    'device',
                    'browser',
                    'started_at',
                    'ended_at',
                    'duration_seconds',
                    'urls_visited',
                    'routes_searched',
                    'user' => [
                        'id',
                        'name',
                        'email',
                    ],
                ],
            ],
            'meta' => [
                'current_page',
                'last_page',
                'per_page',
                'total',
                'has_more_pages',
            ],
        ]);

        $response->assertJsonPath('data.0.user.email', $actor->email);
        $response->assertJsonPath('data.0.session_id', 'session-123');
        $response->assertJsonPath('data.0.urls_visited', ['/dashboard', '/reports']);
        $response->assertJsonPath('data.0.routes_searched.0.origin_label', 'CBD');
        $response->assertJsonPath('data.0.routes_searched.0.route_summaries.0.first_leg.route_id', 'R1');
        $response->assertJsonPath('data.0.started_at', $startedAt->toIso8601String());
        $response->assertJsonPath('meta.total', 1);
    }
}
