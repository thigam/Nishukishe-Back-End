<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Directions;

class BackfillNearestNode extends Command
{
    protected $signature = 'directions:backfill-nearest-node {--host=http://192.168.8.29:5001}';
    protected $description = 'Populate nearest_node_ids for each direction using OSRM /nearest API';

    public function handle()
    {
        $host = rtrim($this->option('host'), '/');

        Directions::query()->chunkById(100, function ($chunk) use ($host) {
            foreach ($chunk as $direction) {
                $response = Http::timeout(5)->get("{$host}/nearest/v1/foot/{$direction->direction_longitude},{$direction->direction_latitude}", [
                    'number' => 3,
                ]);

                if ($response->ok()) {
                    $json = $response->json();
                    $ids = [];
                    foreach ($json['waypoints'] ?? [] as $wp) {
                        foreach ($wp['nodes'] ?? [] as $id) {
                            $ids[] = $id;
                        }
                    }
                    $direction->nearest_node_ids = $ids;
                    // maintain single node column for backwards compatibility
                    $direction->nearest_node_id = $ids[0] ?? null;
                    $direction->save();
                } else {
                    $this->error("Failed for direction {$direction->direction_id}");
                }
            }
        });

        $this->info('nearest_node_ids values updated');
    }
}
