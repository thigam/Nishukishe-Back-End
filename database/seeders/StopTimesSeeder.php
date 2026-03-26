<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StopTimesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // List of route_ids to assign randomly
        $routeIds = [
            '70301000511', '20702000611', '30100000711', '70200000811', '40401001011',
            '50100001111', '30800001411', '50204001511'
        ];

        // List of stop_ids to assign randomly
        $stopIds = [
            '0001RLW', '010044G', '0100AAA', '0100AAE', '0100ACP',
            '0100AEI', '0100AEL', '0100AKA', '0100AL2', '0100AMY'
        ];

        // Stop times data
        $data = [
            ['trip_id' => '80105110', 'arrival_time' => '06:00:00', 'departure_time' => '06:00:20', 'stop_sequence' => 1],
            ['trip_id' => '80105111', 'arrival_time' => '06:04:13', 'departure_time' => '06:04:33', 'stop_sequence' => 2],
            ['trip_id' => '80105112', 'arrival_time' => '06:05:21', 'departure_time' => '06:05:41', 'stop_sequence' => 3],
            ['trip_id' => '80105113', 'arrival_time' => '06:07:15', 'departure_time' => '06:07:35', 'stop_sequence' => 4],
            ['trip_id' => '80105114', 'arrival_time' => '06:08:27', 'departure_time' => '06:08:47', 'stop_sequence' => 5],
            ['trip_id' => '80105115', 'arrival_time' => '06:09:35', 'departure_time' => '06:09:55', 'stop_sequence' => 6],
            ['trip_id' => '80105116', 'arrival_time' => '06:11:09', 'departure_time' => '06:11:29', 'stop_sequence' => 7],
            ['trip_id' => '80105117', 'arrival_time' => '06:12:45', 'departure_time' => '06:13:05', 'stop_sequence' => 8],
            ['trip_id' => '80105118', 'arrival_time' => '06:14:06', 'departure_time' => '06:14:26', 'stop_sequence' => 9],
            ['trip_id' => '80105119', 'arrival_time' => '06:15:23', 'departure_time' => '06:15:43', 'stop_sequence' => 10],
        ];

        // Assign random route_id and stop_id to each stop time
        foreach ($data as &$entry) {
            $entry['route_id'] = $routeIds[array_rand($routeIds)];
            $entry['stop_id'] = $stopIds[array_rand($stopIds)];
        }

        // Insert data into the database
        DB::table('stop_times')->insert($data);
    }
}