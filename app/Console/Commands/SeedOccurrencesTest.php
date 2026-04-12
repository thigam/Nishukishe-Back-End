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
