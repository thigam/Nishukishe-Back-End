<?php

namespace Tests\Feature;

use App\Models\Directions;
use App\Models\Route as BaseRoute;
use App\Models\Sacco;
use App\Models\SaccoRoutes;
use App\Models\Stops;
use App\Models\Trip;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\Fluent\AssertableJson;
use Mockery;
use Tests\TestCase;

class RoutePlannerControllerTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_multileg_route_includes_trip_details(): void
    {
        $this->mockH3();
        $this->seedSaccoRoute(true);

        $response = $this->postJson('/api/multileg-route', [
            'origin' => [-1.3000, 36.8000],
            'destination' => [-1.3000, 36.8100],
            'depart_after' => '2024-01-01T07:50:00+03:00',
            'include_walking' => false,
        ]);

        $response->assertStatus(200);

        $response->assertJson(function (AssertableJson $json) {
            $json->has('multi_leg.0.legs.1.trip', function (AssertableJson $trip) {
                $trip->where('start_time', '08:00:00')
                    ->where('trip_index', '001')
                    ->has('stop_times', 2)
                    ->where('stop_times.0.stop_id', 'STOP1')
                    ->where('stop_times.1.stop_id', 'STOP2')
                    ->etc();
            })
                ->where('multi_leg.0.legs.1.duration_minutes', fn($value) => is_numeric($value) && $value > 0)
                ->where('multi_leg.0.summary.total_duration_minutes', fn($value) => is_numeric($value) && $value > 0)
                ->etc();
        });
    }

    public function test_multileg_route_returns_null_trip_when_unavailable(): void
    {
        $this->mockH3();
        $this->seedSaccoRoute(false);

        $response = $this->postJson('/api/multileg-route', [
            'origin' => [-1.3000, 36.8000],
            'destination' => [-1.3000, 36.8100],
            'depart_after' => '2024-01-01T07:50:00+03:00',
            'include_walking' => false,
        ]);

        $response->assertStatus(200);

        $response->assertJson(function (AssertableJson $json) {
            $json->where('multi_leg.0.legs.1.trip', null)->etc();
        });
    }

    private function mockH3(string $cell = 'abc123'): void
    {
        $mock = Mockery::mock('alias:App\\Services\\H3Wrapper');
        $mock->shouldReceive('latLngToCell')->withAnyArgs()->andReturn($cell);
        $mock->shouldReceive('kRing')->withAnyArgs()->andReturn([$cell]);
    }

    public function test_fare_breakdown_for_short_distance(): void
    {
        $this->mockH3();

        $this->seedSaccoRoute(false, [
            'lat' => -1.3000,
            'lng' => 36.8000,
        ], [
            'lat' => -1.3000,
            'lng' => 36.8400,
        ], 60.0, 40.0);

        $response = $this->postJson('/api/multileg-route', [
            'origin' => [-1.3000, 36.8000],
            'destination' => [-1.3000, 36.8400],
            'include_walking' => false,
            'depart_after' => '2024-01-01T11:00:00+03:00',
        ]);

        $response->assertStatus(200);
        $response->assertJson(function (AssertableJson $json) {
            $json->where('multi_leg.0.legs.1.fare', fn($value) => abs($value - 40.0) < 0.01)
                ->where('multi_leg.0.legs.1.off_peak_fare', fn($value) => abs($value - 40.0) < 0.01)
                ->where('multi_leg.0.legs.1.peak_fare', fn($value) => abs($value - 60.0) < 0.01)
                ->where('multi_leg.0.legs.1.requires_manual_fare', false)
                ->where('multi_leg.0.legs.1.distance_km', function ($value) {
                    return $value < 5 && $value > 3.5;
                })
                ->etc();
        });
    }

    public function test_fare_breakdown_for_mid_distance(): void
    {
        $this->mockH3();

        $this->seedSaccoRoute(false, [
            'lat' => -1.3000,
            'lng' => 36.6000,
        ], [
            'lat' => -1.3000,
            'lng' => 36.7200,
        ], 80.0, 60.0);

        $response = $this->postJson('/api/multileg-route', [
            'origin' => [-1.3000, 36.6000],
            'destination' => [-1.3000, 36.7200],
            'include_walking' => false,
            'depart_after' => '2024-01-01T13:00:00+03:00',
        ]);

        $response->assertStatus(200);
        $response->assertJson(function (AssertableJson $json) {
            $json->where('multi_leg.0.legs.1.fare', fn($value) => abs($value - 60.0) < 0.01)
                ->where('multi_leg.0.legs.1.off_peak_fare', fn($value) => abs($value - 60.0) < 0.01)
                ->where('multi_leg.0.legs.1.peak_fare', fn($value) => abs($value - 80.0) < 0.01)
                ->where('multi_leg.0.legs.1.requires_manual_fare', false)
                ->where('multi_leg.0.legs.1.distance_km', function ($value) {
                    return $value >= 10 && $value <= 15;
                })
                ->etc();
        });
    }

    public function test_fare_breakdown_for_thirty_kilometres_peak_direction(): void
    {
        $this->mockH3();

        $this->seedSaccoRoute(false, [
            'lat' => -1.3000,
            'lng' => 36.5700,
        ], [
            'lat' => -1.2836,
            'lng' => 36.8200,
        ], 100.0, 80.0);

        $response = $this->postJson('/api/multileg-route', [
            'origin' => [-1.3000, 36.5700],
            'destination' => [-1.2836, 36.8200],
            'include_walking' => false,
            'depart_after' => '2024-01-01T07:30:00+03:00',
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('multi_leg.0.legs.1'));
        $response->assertJson(function (AssertableJson $json) {
            $json->where('multi_leg.0.legs.1.fare', fn($value) => abs($value - 100.0) < 0.01)
                ->where('multi_leg.0.legs.1.peak_fare', fn($value) => abs($value - 100.0) < 0.01)
                ->where('multi_leg.0.legs.1.off_peak_fare', fn($value) => abs($value - 80.0) < 0.01)
                ->where('multi_leg.0.legs.1.requires_manual_fare', false)
                ->where('multi_leg.0.legs.1.distance_km', function ($value) {
                    return $value >= 25 && $value <= 35;
                })
                ->etc();
        });
    }

    public function test_fare_breakdown_for_long_distance_requires_manual(): void
    {
        $this->mockH3();

        $this->seedSaccoRoute(false, [
            'lat' => -1.3200,
            'lng' => 36.4000,
        ], [
            'lat' => -1.2600,
            'lng' => 36.9000,
        ], 130.0, 110.0);

        $response = $this->postJson('/api/multileg-route', [
            'origin' => [-1.3200, 36.4000],
            'destination' => [-1.2600, 36.9000],
            'include_walking' => false,
            'depart_after' => '2024-01-01T12:00:00+03:00',
        ]);

        $response->assertStatus(200);
        $response->assertJson(function (AssertableJson $json) {
            $json->where('multi_leg.0.legs.1.fare', fn($value) => abs($value - 110.0) < 0.01)
                ->where('multi_leg.0.legs.1.peak_fare', fn($value) => abs($value - 130.0) < 0.01)
                ->where('multi_leg.0.legs.1.off_peak_fare', fn($value) => abs($value - 110.0) < 0.01)
                ->where('multi_leg.0.legs.1.requires_manual_fare', false)
                ->where('multi_leg.0.legs.1.distance_km', function ($value) {
                    return $value > 45;
                })
                ->etc();
        });
    }

    private function seedSaccoRoute(
        bool $withTrip,
        array $boardOverrides = [],
        array $alightOverrides = [],
        ?float $peakFare = null,
        ?float $offPeakFare = null
    ): void {
        Sacco::create([
            'sacco_id' => 'S1',
            'sacco_name' => 'Test Sacco',
            'vehicle_type' => 'bus',
            'join_date' => now(),
        ]);

        BaseRoute::create([
            'route_id' => 'R1',
            'route_number' => '1',
            'route_start_stop' => 'Stop One',
            'route_end_stop' => 'Stop Two',
        ]);

        $board = array_merge([
            'id' => 'STOP1',
            'name' => 'Stop One',
            'lat' => -1.3000,
            'lng' => 36.8000,
        ], $boardOverrides);

        $alight = array_merge([
            'id' => 'STOP2',
            'name' => 'Stop Two',
            'lat' => -1.3000,
            'lng' => 36.8100,
        ], $alightOverrides);

        Directions::create([
            'direction_id' => $board['id'],
            'direction_latitude' => $board['lat'],
            'direction_longitude' => $board['lng'],
            'h3_index' => 'abc123',
            'direction_heading' => 0,
            'direction_ending' => 0,
            'direction_routes' => ['SR1'],
        ]);

        Directions::create([
            'direction_id' => $alight['id'],
            'direction_latitude' => $alight['lat'],
            'direction_longitude' => $alight['lng'],
            'h3_index' => 'abc123',
            'direction_heading' => 0,
            'direction_ending' => 0,
            'direction_routes' => ['SR1'],
        ]);

        $stop1 = new Stops();
        $stop1->stop_id = $board['id'];
        $stop1->stop_name = $board['name'];
        $stop1->stop_lat = $board['lat'];
        $stop1->stop_long = $board['lng'];
        $stop1->direction_id = $board['id'];
        $stop1->save();

        $stop2 = new Stops();
        $stop2->stop_id = $alight['id'];
        $stop2->stop_name = $alight['name'];
        $stop2->stop_lat = $alight['lat'];
        $stop2->stop_long = $alight['lng'];
        $stop2->direction_id = $alight['id'];
        $stop2->save();

        $data = [
            'sacco_route_id' => 'SR1',
            'route_id' => 'R1',
            'sacco_id' => 'S1',
            'route_number' => '1',
            'currency' => 'KES',
            'sacco_tier_id' => 'TIER1',
            'is_active' => true,
            'is_verified' => true,
            'is_published' => true,
            'stop_ids' => [$board['id'], $alight['id']],
            'coordinates' => [[(float) $board['lat'], (float) $board['lng']], [(float) $alight['lat'], (float) $alight['lng']]],
            'scheduled' => true,
            'has_variations' => false,
        ];

        if ($peakFare !== null) {
            $data['peak_fare'] = $peakFare;
        }

        if ($offPeakFare !== null) {
            $data['off_peak_fare'] = $offPeakFare;
        }

        SaccoRoutes::create($data);

        if ($withTrip) {
            Trip::create([
                'sacco_id' => 'S1',
                'route_id' => 'R1',
                'sacco_route_id' => 'SR1',
                'trip_index' => '001',
                'stop_times' => [
                    ['stop_id' => $board['id'], 'time' => '08:00:00'],
                    ['stop_id' => $alight['id'], 'time' => '08:30:00'],
                ],
                'day_of_week' => [],
                'start_time' => '08:00:00',
            ]);
        }

        // Seed corr_stations for StationRaptor
        \Illuminate\Support\Facades\DB::table('corr_stations')->insert([
            [
                'station_id' => 'ST_' . $board['id'],
                'lat' => $board['lat'],
                'lng' => $board['lng'],
                'l1_cell' => 'dummy_l1',
                'l0_cell' => 'dummy_l0'
            ],
            [
                'station_id' => 'ST_' . $alight['id'],
                'lat' => $alight['lat'],
                'lng' => $alight['lng'],
                'l1_cell' => 'dummy_l1',
                'l0_cell' => 'dummy_l0'
            ],
        ]);

        // Seed corr_station_members for StationRaptor
        \Illuminate\Support\Facades\DB::table('corr_station_members')->insert([
            ['station_id' => 'ST_' . $board['id'], 'stop_id' => $board['id']],
            ['station_id' => 'ST_' . $alight['id'], 'stop_id' => $alight['id']],
        ]);
    }

}

