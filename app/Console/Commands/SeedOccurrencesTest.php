<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SeedOccurrencesTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:seed-occurrences-test';
    protected $description = 'Seed the database with commuter occurrences';

    public function handle()
    {
        $acc = ['Bad crash', 'Pile up', 'Fender bender', 'Avoid this route!', 'Gridlock due to accident', 'Two cars collided', '', 'Watch out'];
        for ($i = 0; $i < 50; $i++) {
            \App\Models\Incident::create([
                'type' => 'accident',
                'lat' => -1.282 + (rand(-10, 10) / 10000),
                'lng' => 36.816 + (rand(-10, 10) / 10000),
                'description' => $acc[array_rand($acc)],
                'reported_at' => now()->subMinutes(rand(1, 120)),
                'is_verified' => false,
                'upvotes' => rand(0, 5),
                'downvotes' => 0
            ]);
        }
        $this->info("Accidents seeded!");

        $cons = ['Roadworks', 'Lane closed', 'Diversion ahead', 'Tarmacking under way', 'Heavy machinery on road'];
        for ($i = 0; $i < 5; $i++) {
            \App\Models\Incident::create([
                'type' => 'construction',
                'lat' => -1.298 + (rand(-15, 15) / 10000),
                'lng' => 36.790 + (rand(-15, 15) / 10000),
                'description' => $cons[array_rand($cons)],
                'reported_at' => now()->subMinutes(rand(1, 180)),
                'is_verified' => false,
                'upvotes' => rand(0, 3),
                'downvotes' => 0
            ]);
        }
        $this->info("Construction seeded!");

        $flood = ['Road submerged', 'Cars stalling', 'Severe flooding', 'Avoid low lying areas'];
        for ($i = 0; $i < 3; $i++) {
            \App\Models\Incident::create([
                'type' => 'flooding',
                'lat' => -1.310 + (rand(-10, 10) / 10000),
                'lng' => 36.820 + (rand(-10, 10) / 10000),
                'description' => $flood[array_rand($flood)],
                'reported_at' => now()->subMinutes(rand(1, 90)),
                'is_verified' => false,
                'upvotes' => rand(0, 4),
                'downvotes' => 0
            ]);
        }
        $this->info("Flooding seeded!");

        $pol = ['Speed trap', 'Random checks', 'Police block', 'Heavy presence'];
        for ($i = 0; $i < 4; $i++) {
            \App\Models\Incident::create([
                'type' => 'police',
                'lat' => -1.250 + (rand(-20, 20) / 10000),
                'lng' => 36.850 + (rand(-20, 20) / 10000),
                'description' => $pol[array_rand($pol)],
                'reported_at' => now()->subMinutes(rand(1, 120)),
                'is_verified' => false,
                'upvotes' => rand(0, 1),
                'downvotes' => 0
            ]);
        }
        $this->info("Police seeded!");

        $trf = ['Bumper to bumper', 'Crawling', 'Westlands jam', 'Traffic standing still', 'Heavy flow', '', 'Stuck!'];
        for ($i = 0; $i < 8; $i++) {
            \App\Models\Incident::create([
                'type' => 'traffic',
                'lat' => -1.267 + (rand(-5, 5) / 10000),
                'lng' => 36.804 + (rand(-5, 5) / 10000),
                'description' => $trf[array_rand($trf)],
                'reported_at' => now()->subMinutes(rand(1, 60)),
                'is_verified' => false,
                'upvotes' => rand(0, 2),
                'downvotes' => 0
            ]);
        }
        $this->info("Traffic seeded!");
    }
}
