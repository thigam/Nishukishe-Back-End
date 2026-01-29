<?php

namespace Tests\Unit;

use App\Models\PostCleanSaccoRoute;
use App\Models\PostCleanTrip;
use App\Models\PreCleanSaccoRoute;
use App\Models\PreCleanTrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class SaccoRouteIdModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
    }

    public function test_trip_models_link_to_routes_by_sacco_route_id(): void
    {
        $saccoRouteId = 'SACCO1_BASE1_001';

        $preRoute = PreCleanSaccoRoute::create([
            'sacco_id'         => 'SACCO1',
            'route_id'         => 'BASE1',
            'sacco_route_id'   => $saccoRouteId,
            'route_start_stop' => 'Start',
            'route_end_stop'   => 'End',
            'coordinates'      => [],
            'stop_ids'         => [],
            'status'           => 'pending',
            'direction_index'  => 1,
        ]);

        $preTrip = PreCleanTrip::create([
            'sacco_route_id' => $saccoRouteId,
            'stop_times'     => [
                ['stop_id' => 1, 'time' => '08:00'],
            ],
            'day_of_week'    => ['mon'],
        ]);

        $this->assertSame($saccoRouteId, $preTrip->sacco_route_id);
        $this->assertSame($saccoRouteId, $preTrip->saccoRoute->sacco_route_id);

        $postRoute = PostCleanSaccoRoute::create([
            'pre_clean_id'     => $preRoute->id,
            'route_id'         => 'BASE1',
            'sacco_route_id'   => $saccoRouteId,
            'sacco_id'         => 'SACCO1',
            'route_start_stop' => 'Start',
            'route_end_stop'   => 'End',
            'coordinates'      => [],
            'stop_ids'         => [],
        ]);

        $postTrip = PostCleanTrip::create([
            'pre_clean_id'   => $preTrip->id,
            'route_id'       => 'BASE1',
            'sacco_id'       => 'SACCO1',
            'sacco_route_id' => $saccoRouteId,
            'trip_times'     => [
                ['stop_id' => 1, 'time' => '08:00'],
            ],
            'day_of_week'    => ['mon'],
        ]);

        $this->assertSame($saccoRouteId, $postTrip->sacco_route_id);
        $this->assertSame($postRoute->sacco_route_id, $postTrip->saccoRoute->sacco_route_id);
    }
}
