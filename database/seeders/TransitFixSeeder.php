<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\CustomStationPolygon;

class TransitFixSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Unified Arusha Station Polygon
        CustomStationPolygon::updateOrCreate(
            ['name' => 'Arusha Unified Terminals'],
            [
                'polygon' => [[-3.36, 36.67], [-3.36, 36.695], [-3.4, 36.695], [-3.4, 36.67], [-3.36, 36.67]],
                'station_id' => 'st:custom:arusha_terminals'
            ]
        );

        // 2. Nairobi Mega Station (CBD + River Road + Railway)
        CustomStationPolygon::updateOrCreate(
            ['name' => 'Nairobi Mega Station'],
            [
                'polygon' => [[-1.275, 36.815], [-1.300, 36.815], [-1.300, 36.840], [-1.275, 36.840], [-1.275, 36.815]],
                'station_id' => 'st:custom:nairobi_mega_station'
            ]
        );

        // 3. Kisumu Mega Station (Main Bus Park + Regional Stages)
        CustomStationPolygon::updateOrCreate(
            ['name' => 'Kisumu Mega Station'],
            [
                'polygon' => [[-0.090, 34.750], [-0.115, 34.750], [-0.115, 34.775], [-0.090, 34.775], [-0.090, 34.750]],
                'station_id' => 'st:custom:kisumu_mega_station'
            ]
        );

        // 4. Kampala Mega Station (Namayiba + Qualicel + Link)
        CustomStationPolygon::updateOrCreate(
            ['name' => 'Kampala Mega Station'],
            [
                'polygon' => [[0.310, 32.570], [0.325, 32.570], [0.325, 32.595], [0.310, 32.595], [0.310, 32.570]],
                'station_id' => 'st:custom:kampala_mega_station'
            ]
        );

        // 5. Dar es Salaam Hub Station (Kibaha + Ubungo + Magufuli)
        CustomStationPolygon::updateOrCreate(
            ['name' => 'Dar es Salaam Hub Station'],
            [
                'polygon' => [[-6.750, 38.900], [-6.850, 38.900], [-6.850, 39.300], [-6.750, 39.300], [-6.750, 38.900]],
                'station_id' => 'st:custom:dar_mega_station'
            ]
        );

        // 6. Mombasa Mega Station (CBD + Mwembe Tayari)
        CustomStationPolygon::updateOrCreate(
            ['name' => 'Mombasa Mega Station'],
            [
                'polygon' => [[-4.030, 39.640], [-4.080, 39.640], [-4.080, 39.690], [-4.030, 39.690], [-4.030, 39.640]],
                'station_id' => 'st:custom:mombasa_mega_station'
            ]
        );

        // 2. Add Missing Hub Regions with H3 Cells
        $regions = [
            ['region_id' => 'ars_cbd', 'name' => 'Arusha CBD', 'h3_cells' => ["87969256dffffff", "87969256effffff", "87969256affffff", "87969256bffffff", "879692569ffffff", "87969256dffffff", "87969256cffffff"]],
            ['region_id' => 'msh_cbd', 'name' => 'Moshi CBD', 'h3_cells' => ["879692013ffffff", "879692012ffffff", "879692010ffffff", "879692011ffffff", "879692016ffffff", "879692014ffffff", "879692015ffffff"]],
            ['region_id' => 'eld_cbd', 'name' => 'Eldoret CBD', 'h3_cells' => ["877a6a469ffffff", "877a6a46dffffff", "877a6a46cffffff", "877a6a461ffffff", "877a6a460ffffff", "877a6a463ffffff", "877a6a462ffffff"]],
            ['region_id' => 'ksm_cbd', 'name' => 'Kisumu CBD', 'h3_cells' => ["877a6b70bffffff", "877a6b70affffff", "877a6b708ffffff", "877a6b709ffffff", "877a6b719ffffff", "877a6b718ffffff", "877a6b70dffffff"]],
            ['region_id' => 'bsa_brd', 'name' => 'Busia Border', 'h3_cells' => ["877a4da90ffffff", "877a4da96ffffff", "877a4da92ffffff", "877a4da93ffffff", "877a4da91ffffff", "877a4da95ffffff", "877a4da94ffffff"]],
            ['region_id' => 'mlb_brd', 'name' => 'Malaba Border', 'h3_cells' => ["877a4d0e6ffffff", "877a4d01bffffff", "877a4d0f5ffffff", "877a4d0e2ffffff", "877a4d0e0ffffff", "877a4d0e4ffffff", "877a4d019ffffff"]],
            ['region_id' => 'nmg_brd', 'name' => 'Namanga Border', 'h3_cells' => ["877a61326ffffff", "877a61a9bffffff", "877a61335ffffff", "877a61322ffffff", "877a61320ffffff", "877a61324ffffff", "877a61a99ffffff"]],
            ['region_id' => 'ubn_trm', 'name' => 'Ubungo Terminal (Dar)', 'h3_cells' => ["877b4cb94ffffff", "877b4c8c9ffffff", "877b4cb96ffffff", "877b4cb90ffffff", "877b4cb95ffffff", "877b4cbb3ffffff", "877b4cbb2ffffff"]],
            ['region_id' => 'jnj_cbd', 'name' => 'Jinja CBD', 'h3_cells' => ["876acd0d0ffffff", "876acd0d6ffffff", "876acd0d2ffffff", "876acd0d3ffffff", "876acd0d1ffffff", "876acd0d5ffffff", "876acd0d4ffffff"]],
            ['region_id' => 'mbr_cbd', 'name' => 'Mbarara CBD', 'h3_cells' => ["876add923ffffff", "876add922ffffff", "876add904ffffff", "876add905ffffff", "876add92effffff", "876add921ffffff", "876add920ffffff"]],
            ['region_id' => 'nmy_bpk', 'name' => 'Namayiba Bus Park (Kampala)', 'h3_cells' => ["876ac8964ffffff", "876acc2d9ffffff", "876ac8966ffffff", "876ac8960ffffff", "876ac8965ffffff", "876acc2cbffffff", "876acc2caffffff"]],
        ];

        foreach ($regions as $r) {
            DB::table('transit_hub_regions')->updateOrInsert(
                ['region_id' => $r['region_id']],
                [
                    'name' => $r['name'],
                    'level' => 'cbd',
                    'h3_res' => 7,
                    'h3_cells' => json_encode($r['h3_cells']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
