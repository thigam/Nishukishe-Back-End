<?php

namespace Tests\Feature;

use App\Models\PostCleanSaccoRoute;
use App\Models\PostCleanStop;
use App\Models\PostCleanTrip;
use App\Models\PreCleanStop;
use App\Models\PreCleanSaccoRoute;
use App\Models\Route as MainRoute;
use App\Models\Sacco;
use App\Models\SaccoRoutes;
use App\Models\Stops;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserRole;
use App\Services\StopIdGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaccoRouteIdFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        @unlink(storage_path('logs/artisan_exec.lock'));
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('logs/artisan_exec.lock'));
        parent::tearDown();
    }

    protected function stopIdFor(float $latitude, float $longitude): string
    {
        /** @var StopIdGenerator $generator */
        $generator = app(StopIdGenerator::class);

        return $generator->generate($latitude, $longitude);
    }

    public function test_pre_clean_to_publish_flow_preserves_sacco_route_id(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\CorsMiddleware::class,
            \App\Http\Middleware\RoleMiddleware::class,
            \App\Http\Middleware\LogUserActivity::class,
        ]);

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'phone' => '123456789',
            'password' => Hash::make('secret'),
            'role' => UserRole::SUPER_ADMIN,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->actingAs($user, 'sanctum');

        $saccoRouteId = 'SACCO1_BASE1_001';

        MainRoute::create([
            'route_id' => 'BASE1',
            'route_number' => '001',
            'route_start_stop' => 'Start',
            'route_end_stop' => 'End',
        ]);

        Sacco::create([
            'sacco_id' => 'SACCO1',
            'sacco_name' => 'Test Sacco',
            'vehicle_type' => 'bus',
            'join_date' => now(),
        ]);

        $preRoute = PreCleanSaccoRoute::create([
            'sacco_id' => 'SACCO1',
            'route_id' => 'BASE1',
            'sacco_route_id' => $saccoRouteId,
            'route_start_stop' => 'Start',
            'route_end_stop' => 'End',
            'coordinates' => [],
            'stop_ids' => [],
            'status' => 'pending',
            'direction_index' => 1,
        ]);

        $createResponse = $this->postJson('/pre-clean/trips', [
            'sacco_route_id' => $saccoRouteId,
            'stop_times' => [
                ['stop_id' => 1, 'time' => '08:00'],
                ['stop_id' => 2, 'time' => '08:30'],
            ],
            'day_of_week' => ['mon'],
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('sacco_route_id', $saccoRouteId);

        $this->assertDatabaseHas('pre_clean_trips', [
            'sacco_route_id' => $saccoRouteId,
        ]);

        $finalizeResponse = $this->postJson("/pre-clean/routes/{$preRoute->id}/finalize", [
            'promote_trips' => true,
        ]);

        $finalizeResponse->assertStatus(200);

        $postTrip = PostCleanTrip::first();
        $this->assertNotNull($postTrip);
        $this->assertSame($saccoRouteId, $postTrip->sacco_route_id);

        $publishResponse = $this->postJson('/post-clean/publish-all');

        $this->assertSame(200, $publishResponse->status(), $publishResponse->getContent());
        $publishResponse->assertJsonFragment(['published' => [$saccoRouteId]]);

        $this->assertDatabaseMissing('post_clean_trips', [
            'sacco_route_id' => $saccoRouteId,
        ]);

        $this->assertDatabaseHas('trips', [
            'sacco_route_id' => $saccoRouteId,
        ]);

        $trip = Trip::where('sacco_route_id', $saccoRouteId)->first();
        $this->assertNotNull($trip);
        $this->assertSame($saccoRouteId, $trip->sacco_route_id);

        $this->assertTrue(
            SaccoRoutes::where('sacco_route_id', $saccoRouteId)->exists()
        );
    }

    public function test_finalize_and_publish_preserve_stop_sacco_route_id(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\CorsMiddleware::class,
            \App\Http\Middleware\RoleMiddleware::class,
            \App\Http\Middleware\LogUserActivity::class,
        ]);

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin2@example.com',
            'phone' => '123456789',
            'password' => Hash::make('secret'),
            'role' => UserRole::SUPER_ADMIN,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->actingAs($user, 'sanctum');

        $saccoRouteId = 'SACCO2_BASE2_001';

        MainRoute::create([
            'route_id' => 'BASE2',
            'route_number' => '002',
            'route_start_stop' => 'Start',
            'route_end_stop' => 'End',
        ]);

        Sacco::create([
            'sacco_id' => 'SACCO2',
            'sacco_name' => 'Second Sacco',
            'vehicle_type' => 'bus',
            'join_date' => now(),
        ]);

        $stopA = PreCleanStop::create([
            'sacco_route_id' => $saccoRouteId,
            'stop_name' => 'Stop A',
            'stop_lat' => 1.2345678,
            'stop_long' => 36.1234567,
            'county_id' => null,
            'direction_id' => null,
            'status' => 'pending',
        ]);

        $stopB = PreCleanStop::create([
            'sacco_route_id' => $saccoRouteId,
            'stop_name' => 'Stop B',
            'stop_lat' => 1.3345678,
            'stop_long' => 36.2234567,
            'county_id' => null,
            'direction_id' => null,
            'status' => 'pending',
        ]);

        $expectedStopIdA = $this->stopIdFor($stopA->stop_lat, $stopA->stop_long);
        $expectedStopIdB = $this->stopIdFor($stopB->stop_lat, $stopB->stop_long);

        $preRoute = PreCleanSaccoRoute::create([
            'sacco_id' => 'SACCO2',
            'route_id' => 'BASE2',
            'sacco_route_id' => $saccoRouteId,
            'route_start_stop' => 'Start',
            'route_end_stop' => 'End',
            'coordinates' => [],
            'stop_ids' => [$stopA->id, $stopB->id],
            'status' => 'pending',
            'direction_index' => null,
        ]);

        $this->postJson("/pre-clean/routes/{$preRoute->id}/finalize", [
            'stop_ids' => [$stopA->id, $stopB->id],
        ])->assertStatus(200);

        $postStops = PostCleanStop::forRoute($saccoRouteId)->get();
        $this->assertCount(2, $postStops);
        $this->assertEqualsCanonicalizing([
            $expectedStopIdA,
            $expectedStopIdB,
        ], $postStops->pluck('stop_id')->all());
        foreach ($postStops as $stop) {
            $this->assertContains($saccoRouteId, $stop->sacco_route_ids ?? []);
        }

        $postRoute = PostCleanSaccoRoute::where('sacco_route_id', $saccoRouteId)->first();
        $this->assertNotNull($postRoute);

        $otherRoute = PostCleanSaccoRoute::create([
            'pre_clean_id' => 9999,
            'route_id' => 'BASE2',
            'sacco_route_id' => 'SACCO2_BASE2_002',
            'sacco_id' => 'SACCO2',
            'route_start_stop' => 'Start',
            'route_end_stop' => 'End',
            'coordinates' => [],
            'stop_ids' => [$expectedStopIdA],
            'peak_fare' => 0,
            'off_peak_fare' => 0,
            'county_id' => null,
            'mode' => null,
            'waiting_time' => null,
            'direction_index' => null,
        ]);

        PostCleanStop::create([
            'pre_clean_id' => $stopA->id,
            'stop_id' => $expectedStopIdA,
            'sacco_route_ids' => [$otherRoute->sacco_route_id],
            'stop_name' => 'Stop A clone',
            'stop_lat' => 1.2345678,
            'stop_long' => 36.1234567,
            'county_id' => null,
            'direction_id' => null,
        ]);

        $publishResponse = $this->postJson('/post-clean/publish', [
            'post_clean_ids' => [$postRoute->id],
        ]);

        $this->assertSame(200, $publishResponse->status(), $publishResponse->getContent());

        $this->assertDatabaseHas('stops', [
            'stop_id' => $expectedStopIdA,
        ]);

        $this->assertSame(1, Stops::where('stop_id', $expectedStopIdA)->count());

        $this->assertFalse(PostCleanStop::forRoute($saccoRouteId)->exists());
        $this->assertTrue(
            PostCleanStop::forRoute($otherRoute->sacco_route_id)
                ->where('stop_name', 'Stop A clone')
                ->exists()
        );
    }

    public function test_finalize_does_not_delete_shared_pre_clean_stop(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\CorsMiddleware::class,
            \App\Http\Middleware\RoleMiddleware::class,
            \App\Http\Middleware\LogUserActivity::class,
        ]);

        $user = User::create([
            'name' => 'Shared Admin',
            'email' => 'shared-admin@example.com',
            'phone' => '123456789',
            'password' => Hash::make('secret'),
            'role' => UserRole::SUPER_ADMIN,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->actingAs($user, 'sanctum');

        $saccoId = 'SACCO_SHARED';
        $baseRoute = 'BASE_SHARED';
        $routeIdOne = 'SACCO_SHARED_BASE_SHARED_001';
        $routeIdTwo = 'SACCO_SHARED_BASE_SHARED_002';

        MainRoute::create([
            'route_id' => $baseRoute,
            'route_number' => '100',
            'route_start_stop' => 'Origin',
            'route_end_stop' => 'Destination',
        ]);

        Sacco::create([
            'sacco_id' => $saccoId,
            'sacco_name' => 'Shared Sacco',
            'vehicle_type' => 'bus',
            'join_date' => now(),
        ]);

        $sharedStop = PreCleanStop::create([
            'sacco_route_ids' => [$routeIdOne, $routeIdTwo],
            'stop_name' => 'Shared Stop',
            'stop_lat' => 1.111111,
            'stop_long' => 36.222222,
            'county_id' => null,
            'direction_id' => null,
            'status' => 'pending',
        ]);

        $preRouteOne = PreCleanSaccoRoute::create([
            'sacco_id' => $saccoId,
            'route_id' => $baseRoute,
            'sacco_route_id' => $routeIdOne,
            'route_start_stop' => 'Origin',
            'route_end_stop' => 'Destination',
            'coordinates' => [],
            'stop_ids' => [$sharedStop->id],
            'status' => 'pending',
            'direction_index' => 1,
        ]);

        PreCleanSaccoRoute::create([
            'sacco_id' => $saccoId,
            'route_id' => $baseRoute,
            'sacco_route_id' => $routeIdTwo,
            'route_start_stop' => 'Origin',
            'route_end_stop' => 'Destination',
            'coordinates' => [],
            'stop_ids' => [$sharedStop->id],
            'status' => 'pending',
            'direction_index' => 2,
        ]);

        $this->postJson("/pre-clean/routes/{$preRouteOne->id}/finalize", [
            'stop_ids' => [$sharedStop->id],
        ])->assertStatus(200);

        $remainingStop = PreCleanStop::find($sharedStop->id);

        $this->assertNotNull($remainingStop, 'Shared stop should remain after finalizing one route');
        $this->assertSame([$routeIdTwo], $remainingStop->sacco_route_ids);
        $this->assertNotContains($routeIdOne, $remainingStop->sacco_route_ids);
    }

    public function test_finalize_replaces_trip_stop_ids_with_finalized_ids(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\CorsMiddleware::class,
            \App\Http\Middleware\RoleMiddleware::class,
            \App\Http\Middleware\LogUserActivity::class,
        ]);

        $user = User::create([
            'name' => 'Trip Admin',
            'email' => 'trip-admin@example.com',
            'phone' => '123456789',
            'password' => Hash::make('secret'),
            'role' => UserRole::SUPER_ADMIN,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->actingAs($user, 'sanctum');

        $saccoRouteId = 'SACCO4_BASE4_001';

        MainRoute::create([
            'route_id' => 'BASE4',
            'route_number' => '004',
            'route_start_stop' => 'Alpha',
            'route_end_stop' => 'Omega',
        ]);

        Sacco::create([
            'sacco_id' => 'SACCO4',
            'sacco_name' => 'Fourth Sacco',
            'vehicle_type' => 'bus',
            'join_date' => now(),
        ]);

        $stop1 = PreCleanStop::create([
            'sacco_route_id' => $saccoRouteId,
            'stop_name' => 'Old Stop 1',
            'stop_lat' => 1.1001,
            'stop_long' => 36.8001,
            'county_id' => null,
            'direction_id' => null,
            'status' => 'pending',
        ]);

        $stop2 = PreCleanStop::create([
            'sacco_route_id' => $saccoRouteId,
            'stop_name' => 'Old Stop 2',
            'stop_lat' => 1.2001,
            'stop_long' => 36.9001,
            'county_id' => null,
            'direction_id' => null,
            'status' => 'pending',
        ]);

        $preRoute = PreCleanSaccoRoute::create([
            'sacco_id' => 'SACCO4',
            'route_id' => 'BASE4',
            'sacco_route_id' => $saccoRouteId,
            'route_start_stop' => 'Alpha',
            'route_end_stop' => 'Omega',
            'coordinates' => [],
            'stop_ids' => [$stop1->id, $stop2->id],
            'status' => 'pending',
            'direction_index' => null,
        ]);

        $this->postJson('/pre-clean/trips', [
            'sacco_route_id' => $saccoRouteId,
            'stop_times' => [
                ['stop_id' => $stop1->id, 'time' => '06:00'],
                ['stop_id' => $stop2->id, 'time' => '06:10'],
            ],
            'day_of_week' => ['mon', 'wed'],
        ])->assertStatus(201);

        $finalStopIds = [9101, 9102];
        $expectedFinalIds = [
            $this->stopIdFor(1.3001, 36.7001),
            $this->stopIdFor(1.4001, 36.6001),
        ];
        $this->assertSame('ST_N0130010_E3670010', $expectedFinalIds[0]);
        $this->assertSame('ST_N0140010_E3660010', $expectedFinalIds[1]);

        $this->postJson("/pre-clean/routes/{$preRoute->id}/finalize", [
            'stop_ids' => $finalStopIds,
            'stop_replacements' => [
                [
                    'pre_id' => $stop1->id,
                    'stop_id' => $finalStopIds[0],
                    'stop_name' => 'Final Stop 1',
                    'stop_lat' => 1.3001,
                    'stop_long' => 36.7001,
                ],
                [
                    'pre_id' => $stop2->id,
                    'stop_id' => $finalStopIds[1],
                    'stop_name' => 'Final Stop 2',
                    'stop_lat' => 1.4001,
                    'stop_long' => 36.6001,
                ],
            ],
            'promote_trips' => true,
        ])->assertStatus(200);

        $postTrip = PostCleanTrip::where('sacco_route_id', $saccoRouteId)->first();
        $this->assertNotNull($postTrip);

        $this->assertSame($expectedFinalIds, array_map(fn($row) => $row['stop_id'], $postTrip->trip_times));
        $this->assertSame(['06:00', '06:10'], array_map(fn($row) => $row['time'], $postTrip->trip_times));

        $postRoute = PostCleanSaccoRoute::where('sacco_route_id', $saccoRouteId)->first();
        $this->assertNotNull($postRoute);
        $this->assertSame($expectedFinalIds, $postRoute->stop_ids);

        $publishResponse = $this->postJson('/post-clean/publish-all');
        $publishResponse->assertStatus(200);

        $trip = Trip::where('sacco_route_id', $saccoRouteId)->first();
        $this->assertNotNull($trip);
        $this->assertSame($expectedFinalIds, array_map(fn($row) => $row['stop_id'], $trip->stop_times));
        $this->assertSame(['06:00', '06:10'], array_map(fn($row) => $row['time'], $trip->stop_times));
    }

    public function test_variation_flow_preserves_sacco_route_id_through_publish(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\CorsMiddleware::class,
            \App\Http\Middleware\RoleMiddleware::class,
            \App\Http\Middleware\LogUserActivity::class,
        ]);

        $user = User::create([
            'name' => 'Cleaner',
            'email' => 'cleaner@example.com',
            'phone' => '123456789',
            'password' => Hash::make('secret'),
            'role' => UserRole::SUPER_ADMIN,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->actingAs($user, 'sanctum');

        $saccoRouteId = 'SACCO3_BASE3_001';

        MainRoute::create([
            'route_id' => 'BASE3',
            'route_number' => '003',
            'route_start_stop' => 'Start',
            'route_end_stop' => 'End',
        ]);

        Sacco::create([
            'sacco_id' => 'SACCO3',
            'sacco_name' => 'Third Sacco',
            'vehicle_type' => 'bus',
            'join_date' => now(),
        ]);

        SaccoRoutes::create([
            'sacco_route_id' => $saccoRouteId,
            'route_id' => 'BASE3',
            'sacco_id' => 'SACCO3',
            'stop_ids' => [],
            'coordinates' => [],
            'scheduled' => false,
            'has_variations' => false,
            'peak_fare' => 0,
            'off_peak_fare' => 0,
        ]);

        $preRoute = PreCleanSaccoRoute::create([
            'sacco_id' => 'SACCO3',
            'route_id' => 'BASE3',
            'sacco_route_id' => $saccoRouteId,
            'route_start_stop' => 'Start',
            'route_end_stop' => 'End',
            'coordinates' => [],
            'stop_ids' => [],
            'status' => 'pending',
            'direction_index' => 1,
        ]);

        PostCleanSaccoRoute::create([
            'pre_clean_id' => $preRoute->id,
            'route_id' => 'BASE3',
            'sacco_route_id' => $saccoRouteId,
            'sacco_id' => 'SACCO3',
            'route_start_stop' => 'Start',
            'route_end_stop' => 'End',
            'coordinates' => [],
            'stop_ids' => [],
            'peak_fare' => 0,
            'off_peak_fare' => 0,
            'county_id' => null,
            'mode' => null,
            'waiting_time' => null,
            'direction_index' => 1,
        ]);

        $createResponse = $this->postJson('/pre-clean/variations', [
            'sacco_route_id' => $saccoRouteId,
            'coordinates' => [[-1.0, 36.8], [-1.1, 36.9]],
            'stop_ids' => ['PRE_STOP_1', 'PRE_STOP_2'],
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('sacco_route_id', $saccoRouteId);

        $preVariationId = $createResponse->json('id');

        $this->assertDatabaseHas('pre_clean_variations', [
            'id' => $preVariationId,
            'sacco_route_id' => $saccoRouteId,
        ]);

        $approveResponse = $this->postJson("/pre-clean/variations/{$preVariationId}/approve");

        $approveResponse->assertStatus(200)
            ->assertJsonPath('sacco_route_id', $saccoRouteId);

        $postVariationId = $approveResponse->json('id');

        $this->assertDatabaseHas('post_clean_variations', [
            'id' => $postVariationId,
            'sacco_route_id' => $saccoRouteId,
        ]);

        $publishResponse = $this->postJson('/post-clean/publish-all');


        $publishResponse->assertStatus(200)
            ->assertJsonFragment(['published' => [$saccoRouteId]]);

        $this->assertDatabaseHas('variations', [
            'sacco_route_id' => $saccoRouteId,
        ]);

        $this->assertDatabaseMissing('post_clean_variations', [
            'id' => $postVariationId,
        ]);
    }
}
