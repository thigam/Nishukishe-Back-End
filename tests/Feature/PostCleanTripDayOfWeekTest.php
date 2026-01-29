<?php

namespace Tests\Feature;

use App\Models\PostCleanTrip;
use App\Models\PreCleanSaccoRoute;
use App\Models\Route as MainRoute;
use App\Models\Sacco;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostCleanTripDayOfWeekTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
    }

    public function test_day_of_week_metadata_survives_finalize_and_publish(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\CorsMiddleware::class,
            \App\Http\Middleware\RoleMiddleware::class,
            \App\Http\Middleware\LogUserActivity::class,
        ]);

        $user = User::create([
            'name'     => 'Admin',
            'email'    => 'dayofweek@example.com',
            'phone'    => '123456789',
            'password' => Hash::make('secret'),
            'role'     => UserRole::SUPER_ADMIN,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->actingAs($user, 'sanctum');

        $saccoRouteId = 'SACCO3_BASE3_001';
        $dayOfWeek = ['mon', 'wed'];

        MainRoute::create([
            'route_id'        => 'BASE3',
            'route_number'    => '003',
            'route_start_stop'=> 'Start',
            'route_end_stop'  => 'End',
        ]);

        Sacco::create([
            'sacco_id'    => 'SACCO3',
            'sacco_name'  => 'Third Sacco',
            'vehicle_type'=> 'bus',
            'join_date'   => now(),
        ]);

        $preRoute = PreCleanSaccoRoute::create([
            'sacco_id'         => 'SACCO3',
            'route_id'         => 'BASE3',
            'sacco_route_id'   => $saccoRouteId,
            'route_start_stop' => 'Start',
            'route_end_stop'   => 'End',
            'coordinates'      => [],
            'stop_ids'         => [],
            'status'           => 'pending',
            'direction_index'  => 1,
        ]);

        $this->postJson('/pre-clean/trips', [
            'sacco_route_id' => $saccoRouteId,
            'stop_times'     => [
                ['stop_id' => 1, 'time' => '09:00'],
                ['stop_id' => 2, 'time' => '09:30'],
            ],
            'day_of_week'    => $dayOfWeek,
        ])->assertStatus(201);

        $this->postJson("/pre-clean/routes/{$preRoute->id}/finalize", [
            'promote_trips' => true,
        ])->assertStatus(200);

        $postTrip = PostCleanTrip::first();
        $this->assertNotNull($postTrip);
        $this->assertSame($dayOfWeek, $postTrip->day_of_week);

        $publishResponse = $this->postJson('/post-clean/publish-all');
        $this->assertSame(200, $publishResponse->status(), $publishResponse->getContent());

        $trip = Trip::where('sacco_route_id', $saccoRouteId)->first();
        $this->assertNotNull($trip);
        $this->assertSame($dayOfWeek, $trip->day_of_week);
    }
}
