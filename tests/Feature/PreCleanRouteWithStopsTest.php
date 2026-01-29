<?php

namespace Tests\Feature;

use App\Http\Controllers\PreCleanSaccoRouteController;
use App\Models\PreCleanSaccoRoute;
use App\Models\PreCleanStop;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PreCleanRouteWithStopsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_show_with_stops_includes_scope_linked_stops_when_explicit_ids_missing(): void
    {
        $saccoRouteId = 'SACCO_TEST_BASE_001';

        $route = PreCleanSaccoRoute::create([
            'sacco_id' => 'SACCO_TEST',
            'route_id' => 'BASE_TEST',
            'sacco_route_id' => $saccoRouteId,
            'route_start_stop' => 'Start',
            'route_end_stop' => 'End',
            'coordinates' => [],
            'stop_ids' => [],
            'status' => 'pending',
        ]);

        $stopA = PreCleanStop::create([
            'sacco_route_ids' => [$saccoRouteId],
            'stop_name' => 'Stop A',
            'stop_lat' => 1.2345678,
            'stop_long' => 36.1234567,
            'status' => 'pending',
        ]);

        $stopB = PreCleanStop::create([
            'sacco_route_ids' => [$saccoRouteId],
            'stop_name' => 'Stop B',
            'stop_lat' => 1.3345678,
            'stop_long' => 36.2234567,
            'status' => 'pending',
        ]);

        $response = app(PreCleanSaccoRouteController::class)->showWithStops($route->id);

        $this->assertSame(200, $response->status());

        $payload = $response->getData(true);

        $this->assertArrayHasKey('stops', $payload);
        $this->assertCount(2, $payload['stops']);

        $returnedIds = collect($payload['stops'])->pluck('id')->all();

        $this->assertContains($stopA->id, $returnedIds);
        $this->assertContains($stopB->id, $returnedIds);
    }
}

