<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StopsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */

    public function run(): void
    {
        $stops = [
            ['stop_id' => '0001RLW', 'stop_name' => 'Railways', 'stop_lat' => -1.290884, 'stop_long' => 36.828242, 'county_id' => 47, 'direction_id' =>'D00001'],
            ['stop_id' => '010044G', 'stop_name' => '44 Githurai', 'stop_lat' => -1.195514, 'stop_long' => 36.902695, 'county_id' => 47, 'direction_id' =>'D00001'],
            ['stop_id' => '0100AAA', 'stop_name' => 'Sahala', 'stop_lat' => -1.206232, 'stop_long' => 36.790681, 'county_id' => 47, 'direction_id' =>'D00001'],
            ['stop_id' => '0100AAE', 'stop_name' => 'Aga Khan Hospital Entrance', 'stop_lat' => -1.26177, 'stop_long' => 36.82218, 'county_id' => 47, 'direction_id' =>'D00001'],
            ['stop_id' => '0100ACP', 'stop_name' => 'PCEA', 'stop_lat' => -1.184213, 'stop_long' => 36.907515, 'county_id' => 47, 'direction_id' =>'D00001'],
            ['stop_id' => '0100AEI', 'stop_name' => 'Nyari Police', 'stop_lat' => -1.225512, 'stop_long' => 36.788042, 'county_id' => 47, 'direction_id' =>'D00001'],
            ['stop_id' => '0100AEL', 'stop_name' => 'Membley Stage', 'stop_lat' => -1.160297, 'stop_long' => 36.922367, 'county_id' => 47, 'direction_id' =>'D00001'],
            ['stop_id' => '0100AKA', 'stop_name' => 'Chamoka', 'stop_lat' => -1.218551, 'stop_long' => 36.774437, 'county_id' => 47, 'direction_id' =>'D00001'],
            ['stop_id' => '0100AL2', 'stop_name' => 'Lane 26', 'stop_lat' => -1.202838, 'stop_long' => 36.769285, 'county_id' => 47, 'direction_id' =>'D00001'],
            ['stop_id' => '0100AMY', 'stop_name' => 'Kwa Nyama', 'stop_lat' => -1.204494, 'stop_long' => 36.786014, 'county_id' => 47, 'direction_id' =>'D00001'],
        ];

        DB::table('stops')->insert($stops);
    }
}
