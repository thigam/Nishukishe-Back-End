<?php

namespace Tests\Unit;

use App\Http\Controllers\RoutePlannerController;
use App\Services\BusTravelTimeService;
use App\Services\FareCalculator;
use App\Services\WalkRouter;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class RoutePlannerControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $app = new Container();
        $app->instance('db', $capsule->getDatabaseManager());
        Facade::setFacadeApplication($app);

        $schema = $capsule->schema();
        $schema->create('route_stop', function ($table) {
            $table->string('route_id');
            $table->string('stop_id');
            $table->integer('sequence');
        });
        $schema->create('transfer_edges', function ($table) {
            $table->increments('id');
            $table->string('from_stop_id');
            $table->string('to_stop_id');
            $table->integer('walk_time_seconds')->default(0);
            $table->text('geometry')->nullable();
        });
        $schema->create('sacco_routes', function ($table) {
            $table->string('route_id');
            $table->string('sacco_id');
            $table->string('sacco_route_id')->primary();
            $table->text('stop_ids')->nullable();
            $table->boolean('scheduled')->default(false);
        });
        $schema->create('directions', function ($table) {
            $table->string('direction_id')->primary();
            $table->text('direction_routes')->nullable();
        });

        $capsule->table('route_stop')->insert([
            ['route_id' => 'R1', 'stop_id' => 'S1', 'sequence' => 1],
            ['route_id' => 'R1', 'stop_id' => 'S2', 'sequence' => 2],
            ['route_id' => 'R1', 'stop_id' => 'S3', 'sequence' => 3],
            ['route_id' => 'R2', 'stop_id' => 'S2', 'sequence' => 1],
            ['route_id' => 'R2', 'stop_id' => 'S3', 'sequence' => 2],
            ['route_id' => 'R2', 'stop_id' => 'D', 'sequence' => 3],
        ]);
        $capsule->table('sacco_routes')->insert([
            ['route_id' => 'R1', 'sacco_id' => 'SA', 'sacco_route_id' => 'SR1', 'stop_ids' => json_encode(['S1', 'S2', 'S3']), 'scheduled' => false],
            ['route_id' => 'R2', 'sacco_id' => 'SA', 'sacco_route_id' => 'SR2A', 'stop_ids' => json_encode(['S2', 'S3', 'D']), 'scheduled' => false],
            ['route_id' => 'R2', 'sacco_id' => 'SB', 'sacco_route_id' => 'SR2B', 'stop_ids' => json_encode(['S2', 'S3', 'D']), 'scheduled' => false],
        ]);
        $capsule->table('directions')->insert([
            ['direction_id' => 'S1', 'direction_routes' => json_encode(['SR1'])],
            ['direction_id' => 'S2', 'direction_routes' => json_encode(['SR1', 'SR2A', 'SR2B'])],
            ['direction_id' => 'S3', 'direction_routes' => json_encode(['SR1', 'SR2A', 'SR2B'])],
            ['direction_id' => 'D', 'direction_routes' => json_encode(['SR2A', 'SR2B'])],
        ]);
    }

    public function test_sacco_route_combinations_collapsed(): void
    {
        $this->markTestSkipped('Legacy BFS helper removed from RoutePlannerController.');

        $controller = new RoutePlannerController(
            new WalkRouter('http://localhost'),
            new FareCalculator(),
            new BusTravelTimeService(),
            $this->createMock(\App\Services\StationRaptor::class)
        );

        $orig = new Collection([['stop_id' => 'S1']]);
        $dest = new Collection([['stop_id' => 'D']]);

        $ref = new \ReflectionClass(RoutePlannerController::class);
        $method = $ref->getMethod('bfsMultiple');
        $method->setAccessible(true);
        $result = $method->invoke($controller, $orig, $dest, 10, 10, false);

        $this->assertCount(2, $result['single_leg']);
        $labels = array_map(fn($path) => array_map(fn($step) => $step['label'], $path), $result['single_leg']);
        $expected = [
            ['start', 'bus via SR1', 'bus via SR2A', 'arrive'],
            ['start', 'bus via SR1', 'bus via SR2B', 'arrive'],
        ];
        $this->assertEqualsCanonicalizing($expected, $labels);
    }

    public function test_walk_from_filters_edges_by_distance_cap(): void
    {
        Capsule::table('transfer_edges')->delete();

        // Get the real cap
        $ref = new \ReflectionClass(RoutePlannerController::class);
        $capM = $ref->getConstant('WALK_CAP_M');

        // Just pick two arbitrary times; we don't need to tightly couple to the internal formula
        Capsule::table('transfer_edges')->insert([
            'from_stop_id' => 'S1',
            'to_stop_id' => 'S2',
            'walk_time_seconds' => 600,
            'geometry' => null,
        ]);

        Capsule::table('transfer_edges')->insert([
            'from_stop_id' => 'S1',
            'to_stop_id' => 'S3',
            'walk_time_seconds' => 1600,
            'geometry' => null,
        ]);

        $walkRouter = $this->getMockBuilder(WalkRouter::class)
            ->disableOriginalConstructor()
            ->getMock();

        $busTravelService = $this->getMockBuilder(BusTravelTimeService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $stationRaptor = $this->getMockBuilder(\App\Services\StationRaptor::class)
            ->disableOriginalConstructor()
            ->getMock();

        $controller = new RoutePlannerController($walkRouter, new FareCalculator(), $busTravelService, $stationRaptor);

        $refController = new \ReflectionClass(RoutePlannerController::class);
        $method = $refController->getMethod('walkFromOnTheFly');
        $method->setAccessible(true);

        $edges = $method->invoke($controller, 'S1');

        // 1) We should get at least one candidate from S1
        $this->assertNotEmpty($edges);

        // 2) Every edge returned must respect the cap
        foreach ($edges as $edge) {
            $this->assertArrayHasKey('dist', $edge);
            $this->assertLessThanOrEqual($capM, $edge['dist']);
            $this->assertArrayHasKey('to', $edge);
        }

        // 3) Sanity check: S2 (the shorter walk) is among the candidates
        $destinations = array_column($edges, 'to');
        $this->assertContains('S2', $destinations);
    }

}
