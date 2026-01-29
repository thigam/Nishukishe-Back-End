<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserRole;
use App\Services\HealthDashboardService;
use App\Services\TestResultsAggregator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class HealthDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        $this->withoutMiddleware([
            \App\Http\Middleware\LogUserActivity::class,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    protected function authenticateSuperAdmin(): User
    {
        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'super.admin@example.com',
            'phone' => '254700000000',
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

    public function test_super_admin_receives_dashboard_payload(): void
    {
        $this->authenticateSuperAdmin();

        $payload = [
            'status' => 'passed',
            'started_at' => '2024-01-01T10:00:00+00:00',
            'finished_at' => '2024-01-01T10:05:00+00:00',
            'generated_at' => '2024-01-01T10:05:30+00:00',
            'areas' => [
                [
                    'key' => 'backend_phpunit',
                    'name' => 'Backend (PHPUnit)',
                    'status' => 'passed',
                    'metrics' => [
                        'suite_count' => 1,
                        'tests_total' => 10,
                        'tests_passed' => 10,
                        'tests_failed' => 0,
                        'pass_rate' => 100.0,
                        'execution_time_seconds' => 12.5,
                    ],
                    'suites' => [],
                    'logs' => [],
                    'last_run_at' => '2024-01-01T10:05:00+00:00',
                ],
            ],
            'dependencies' => [
                [
                    'key' => 'node',
                    'name' => 'Node.js',
                    'status' => 'healthy',
                    'command' => 'node --version',
                    'output' => 'v18.17.0',
                    'checked_at' => '2024-01-01T10:05:10+00:00',
                    'duration_seconds' => 0.01,
                ],
            ],
            'suites' => [],
            'logs' => [],
        ];

        $service = Mockery::mock(HealthDashboardService::class);
        $service->shouldReceive('buildDashboard')
            ->once()
            ->withNoArgs()
            ->andReturn($payload);

        app()->instance(HealthDashboardService::class, $service);

        $response = $this->getJson('/api/health-dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'started_at',
            'finished_at',
            'generated_at',
            'areas',
            'dependencies',
            'suites',
            'logs',
        ]);
        $response->assertJsonPath('areas.0.key', 'backend_phpunit');
        $response->assertJsonPath('dependencies.0.status', 'healthy');
    }

    public function test_run_endpoint_wraps_tests_run_output(): void
    {
        $this->authenticateSuperAdmin();

        $summary = [
            'status' => 'failed',
            'started_at' => '2024-01-02T10:00:00+00:00',
            'finished_at' => '2024-01-02T10:05:00+00:00',
            'dependencies' => [
                [
                    'key' => 'composer',
                    'name' => 'Composer dependencies',
                    'success' => true,
                ],
            ],
            'suites' => [],
            'logs' => [],
        ];

        $service = Mockery::mock(HealthDashboardService::class);
        $service->shouldReceive('buildDashboard')
            ->once()
            ->with($summary)
            ->andReturn([
                'status' => 'failed',
                'started_at' => '2024-01-02T10:00:00+00:00',
                'finished_at' => '2024-01-02T10:05:00+00:00',
                'generated_at' => '2024-01-02T10:05:30+00:00',
                'areas' => [],
                'dependencies' => [
                    [
                        'key' => 'composer',
                        'name' => 'Composer dependencies',
                        'success' => true,
                    ],
                ],
                'suites' => [],
                'logs' => [],
            ]);

        $aggregator = Mockery::mock(TestResultsAggregator::class);
        $aggregator->shouldReceive('readLatestSummary')
            ->once()
            ->andReturn($summary);

        app()->instance(HealthDashboardService::class, $service);
        app()->instance(TestResultsAggregator::class, $aggregator);

        Artisan::shouldReceive('call')
            ->once()
            ->with('tests:run', [], Mockery::type(BufferedOutput::class))
            ->andReturnUsing(function (string $command, array $parameters, BufferedOutput $output): int {
                $output->writeln('health check run complete');

                return 0;
            });

        $response = $this->postJson('/api/health-dashboard/run');

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'status',
            'areas',
            'dependencies',
            'suites',
            'logs',
            'artisan_output',
            'exit_code',
        ]);
        $response->assertJsonPath('artisan_output', 'health check run complete');
        $response->assertJsonPath('exit_code', 0);
        $response->assertJsonPath('dependencies.0.key', 'composer');
    }
}
