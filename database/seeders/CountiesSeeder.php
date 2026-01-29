<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountiesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $counties = [
            ['county_id' => 1, 'county_name' => 'Baringo', 'county_hq' => 'Eldama Ravine'],
            ['county_id' => 2, 'county_name' => 'Bomet', 'county_hq' => 'Bomet Town'],
            ['county_id' => 3, 'county_name' => 'Bungoma', 'county_hq' => 'Webuye'],
            ['county_id' => 4, 'county_name' => 'Busia', 'county_hq' => 'Busia'],
            ['county_id' => 5, 'county_name' => 'Cakue', 'county_hq' => 'Isiolo'],
            ['county_id' => 6, 'county_name' => 'Elgeyo Marakwet', 'county_hq' => 'Iten'],
            ['county_id' => 7, 'county_name' => 'Embu', 'county_hq' => 'Embu'],
            ['county_id' => 8, 'county_name' => 'Garissa', 'county_hq' => 'Garissa'],
            ['county_id' => 9, 'county_name' => 'Homa Bay', 'county_hq' => 'Homa Bay'],
            ['county_id' => 10, 'county_name' => 'Isiolo', 'county_hq' => 'Isiolo'],
            ['county_id' => 11, 'county_name' => 'Kajiado', 'county_hq' => 'Kajiado'],
            ['county_id' => 12, 'county_name' => 'Kirinyaga', 'county_hq' => 'Kutus'],
            ['county_id' => 13, 'county_name' => 'Kisii', 'county_hq' => 'Kisii Town'],
            ['county_id' => 14, 'county_name' => 'Kisumu', 'county_hq' => 'Kisumu City'],
            ['county_id' => 15, 'county_name' => 'Kitui', 'county_hq' => 'Kitui'],
            ['county_id' => 16, 'county_name' => 'Lamu', 'county_hq' => 'Lamu'],
            ['county_id' => 17, 'county_name' => 'Laikipia', 'county_hq' => 'Nanyuki'],
            ['county_id' => 18, 'county_name' => 'Lamu', 'county_hq' => 'Lamu'],
            ['county_id' => 19, 'county_name' => 'Machakos', 'county_hq' => 'Machakos Town'],
            ['county_id' => 20, 'county_name' => 'Makueni', 'county_hq' => 'Wote'],
            ['county_id' => 21, 'county_name' => 'Marsabit', 'county_hq' => 'Marsabit Town'],
            ['county_id' => 22, 'county_name' => 'Migori', 'county_hq' => 'Migori Town'],
            ['county_id' => 23, 'county_name' => 'Nyamira', 'county_hq' => 'Nyamira Town'],
            ['county_id' => 24, 'county_name' => 'Nairobi', 'county_hq' => 'Nairobi City'],
            ['county_id' => 25, 'county_name' => 'Nakuru', 'county_hq' => 'Nakuru Town'],
            ['county_id' => 26, 'county_name' => 'Nandi', 'county_hq' => 'Kapsabet'],
            ['county_id' => 27, 'county_name' => 'Nyandarua', 'county_hq' => 'Nyahururu'],
            ['county_id' => 28, 'county_name' => 'Nyeri', 'county_hq' => 'Nyeri Town'],
            ['county_id' => 29, 'county_name' => 'Siaya', 'county_hq' => 'Siaya Town'],
            ['county_id' => 30, 'county_name' => 'Samburu', 'county_hq' => 'Maralal'],
            ['county_id' => 31, 'county_name' => 'Taita Taveta', 'county_hq' => 'Wundanyi'],
            ['county_id' => 32, 'county_name' => 'Tana River', 'county_hq' => 'Hola'],
            ['county_id' => 33, 'county_name' => 'Taraba', 'county_hq' => 'Taraba Town'],
            ['county_id' => 34, 'county_name' => 'Uasin Gishu', 'county_hq' => 'Eldoret'],
            ['county_id' => 35, 'county_name' => 'Wajir', 'county_hq' => 'Wajir Town'],
            ['county_id' => 36, 'county_name' => 'West Pokot', 'county_hq' => 'Kapenguria'],
            ['county_id' => 47, 'county_name' => 'Nairobi', 'county_hq' => 'Nairobi']
        ];

        foreach ($counties as $county) {
            DB::table('counties')->updateOrInsert(
                ['county_id' => $county['county_id']],  // match on county_id
                $county                                 // values to insert/update
            );
        }
    }
}

