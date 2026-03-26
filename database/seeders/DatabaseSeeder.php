<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Disable FK checks for the truncation step
        // DB::statement('PRAGMA foreign_keys = OFF');

        // Truncate in the correct order
        // DB::table('stop_times')->truncate();
        // DB::table('sacco_routes')->truncate();
        // DB::table('stops')->truncate();
        // DB::table('saccos')->truncate();
        // DB::table('counties')->truncate();
        // DB::table('users')->truncate();
        // // …etc…

        // Re-enable FK checks before seeding
        // DB::statement('PRAGMA foreign_keys = ON');

        // Now call each seeder
        $this->call([
            SaccoTierSeeder::class,
            UsersSeeder::class,
            CountiesSeeder::class,
            StopsSeeder::class,
            SaccoSeeder::class,
            RoutesTableSeeder::class,
            SaccoRoutesSeeder::class,
            StopTimesSeeder::class,
            SetScheduledFlagSeeder::class,
            CommentSeeder::class,
            // …any others…
        ]);

        DB::table('sacco_routes')
            ->whereIn('sacco_route_id', function ($q) {
                $q->select('sacco_route_id')->from('trips');
            })
            ->update(['scheduled' => true]);
    }
}

