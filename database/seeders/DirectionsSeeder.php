<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DirectionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */

     //directions: Thika,juja,kenol,ruiru,kikuyu,kiambu,ngong,kajiado,ngong road
    public function run(): void
    {
        DB::table('directions')->insert([
            [
                'direction_id' => 'D00001',
                'direction_heading' => 'Thika',
                'direction_latitude' => '-1.0000',
                'direction_longitude' => '38.0000',
                'direction_routes' => json_encode(['R00001', 'R00002']),
                'direction_ending' => json_encode(['R00001', 'R00004']),
            ],
            [
                'direction_id' => 'D00002',
                'direction_heading' => 'Juja',
                'direction_latitude' =>'-1.0000',
                'direction_longitude' => '37.0000',
                'direction_routes' => json_encode(['R00003', 'R00004']),
                'direction_ending' => json_encode(['R00002', 'R00005']),
            ],
        ]);
    }
    public function down(): void
    {
        DB::table('directions')->truncate();
    }
}
