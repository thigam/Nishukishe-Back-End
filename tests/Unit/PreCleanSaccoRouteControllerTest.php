<?php

namespace Tests\Unit;

use App\Http\Controllers\PreCleanSaccoRouteController;
use App\Models\PreCleanSaccoRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PreCleanSaccoRouteControllerTest extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as protected baseMigrateFreshUsing;
    }

    protected function migrateFreshUsing(): array
    {
        return array_merge($this->baseMigrateFreshUsing(), [
            '--path' => 'database/migrations/2025_06_30_121213_create_pre_clean_sacco_routes_table.php',
        ]);
    }

    public function test_index_filters_by_sacco_and_route_when_both_parameters_are_provided(): void
    {
        PreCleanSaccoRoute::create([
            'sacco_id' => 'SACCO-1',
            'route_id' => 'ROUTE-1',
            'route_start_stop' => 'Start A',
            'route_end_stop' => 'End A',
        ]);

        PreCleanSaccoRoute::create([
            'sacco_id' => 'SACCO-1',
            'route_id' => 'ROUTE-2',
            'route_start_stop' => 'Start B',
            'route_end_stop' => 'End B',
        ]);

        PreCleanSaccoRoute::create([
            'sacco_id' => 'SACCO-2',
            'route_id' => 'ROUTE-1',
            'route_start_stop' => 'Start C',
            'route_end_stop' => 'End C',
        ]);

        $controller = new PreCleanSaccoRouteController();

        $request = Request::create('/pre-clean/routes', 'GET', [
            'sacco_id' => 'SACCO-1',
            'route_id' => 'ROUTE-1',
        ]);

        $response = $controller->index($request);
        $data = $response->getData(true);

        $this->assertCount(1, $data);
        $this->assertSame('SACCO-1', $data[0]['sacco_id']);
        $this->assertSame('ROUTE-1', $data[0]['route_id']);
    }
}
