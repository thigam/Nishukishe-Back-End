<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\H3Wrapper;

class HubsSeedRegionsDemo extends Command
{
    protected $signature = 'hubs:seed-regions-demo';
    protected $description = 'Create demo regions (Nairobi CBD, Mombasa CBD) with H3 cells';

    public function handle()
    {
        $regions = [
            ['region_id'=>'nbo_cbd','name'=>'Nairobi CBD','level'=>'cbd','lat'=>-1.284, 'lng'=>36.822, 'res'=>7, 'kr'=>1],
	    ['region_id'=>'wst_cbd','name'=>'Westlands','level'=>'cbd','lat'=>-1.26530, 'lng'=>36.80220, 'res'=>7, 'kr'=>1],
	    ['region_id'=>'msa_cbd','name'=>'Mombasa CBD','level'=>'cbd','lat'=>-4.055, 'lng'=>39.666, 'res'=>7, 'kr'=>1],
	        ['region_id'=>'ksm_cbd','name'=>'Kisumu CBD','level'=>'cbd','lat'=>-0.091702,'lng'=>34.768006,'res'=>7,'kr'=>2],
    ['region_id'=>'nyr_cbd','name'=>'Nyeri CBD','level'=>'cbd','lat'=>-0.420053,'lng'=>36.947471,'res'=>7,'kr'=>2],
    ['region_id'=>'nyk_cbd','name'=>'Nanyuki CBD','level'=>'cbd','lat'=>0.013106,'lng'=>37.072000,'res'=>7,'kr'=>2],
    ['region_id'=>'eld_cbd','name'=>'Eldoret CBD','level'=>'cbd','lat'=>0.514277,'lng'=>35.269779,'res'=>7,'kr'=>2],
    ['region_id'=>'nrk_cbd','name'=>'Narok CBD','level'=>'cbd','lat'=>-1.080611,'lng'=>35.860000,'res'=>7,'kr'=>2],
    ['region_id'=>'nkr_cbd','name'=>'Nakuru CBD','level'=>'cbd','lat'=>-0.303099,'lng'=>36.072250,'res'=>7,'kr'=>2],
    ['region_id'=>'thk_cbd','name'=>'Thika CBD','level'=>'cbd','lat'=>-1.039600,'lng'=>37.085300,'res'=>7,'kr'=>2],
	    ['region_id'=>'gth_cbd','name'=>'Githurai CBD','level'=>'cbd','lat'=>-1.20549,  'lng'=>36.91390,   'res'=>7,'kr'=>2],
    ['region_id'=>'kky_cbd','name'=>'Kikuyu CBD','level'=>'cbd','lat'=>-1.24627,   'lng'=>36.66291,   'res'=>7,'kr'=>2],
    ['region_id'=>'kjd_cbd','name'=>'Kajiado CBD','level'=>'cbd','lat'=>-1.85238,  'lng'=>36.77680,   'res'=>7,'kr'=>2],

    // ---------- Strong optional candidates ----------
    ['region_id'=>'rui_cbd','name'=>'Ruiru CBD','level'=>'cbd','lat'=>-1.15000,    'lng'=>36.95000,   'res'=>7,'kr'=>2],
    ['region_id'=>'rng_cbd','name'=>'Ongata Rongai CBD','level'=>'cbd','lat'=>-1.39574,'lng'=>36.75730,'res'=>7,'kr'=>2],
    ['region_id'=>'ktg_cbd','name'=>'Kitengela CBD','level'=>'cbd','lat'=>-1.47650,'lng'=>36.96240,   'res'=>7,'kr'=>2],
    ['region_id'=>'mch_cbd','name'=>'Machakos CBD','level'=>'cbd','lat'=>-1.50750,'lng'=>37.26340,    'res'=>7,'kr'=>2],
    ['region_id'=>'mru_cbd','name'=>'Meru CBD','level'=>'cbd','lat'=>0.04700,     'lng'=>37.65290,    'res'=>7,'kr'=>2],
    ['region_id'=>'emb_cbd','name'=>'Embu CBD','level'=>'cbd','lat'=>-0.53600,    'lng'=>37.45870,    'res'=>7,'kr'=>2],
    ['region_id'=>'krc_cbd','name'=>'Kericho CBD','level'=>'cbd','lat'=>-0.36740, 'lng'=>35.28330,    'res'=>7,'kr'=>2],
    ['region_id'=>'mln_cbd','name'=>'Malindi CBD','level'=>'cbd','lat'=>-3.21799, 'lng'=>40.11692,    'res'=>7,'kr'=>2],
        // --- Mombasa coastal localities (beyond Mombasa CBD) ---
    ['region_id'=>'dni_cbd','name'=>'Diani Beach','level'=>'cbd',
        'lat'=>-4.29283,'lng'=>39.57209,'res'=>7,'kr'=>2],   // Ukunda–Diani area
    ['region_id'=>'wtm_cbd','name'=>'Watamu','level'=>'cbd',
        'lat'=>-3.35000,'lng'=>40.01700,'res'=>7,'kr'=>2],
    ['region_id'=>'nya_cbd','name'=>'Nyali','level'=>'cbd',
        'lat'=>-4.05000,'lng'=>39.70000,'res'=>7,'kr'=>2],
    ['region_id'=>'bmb_cbd','name'=>'Bamburi','level'=>'cbd',
    'lat'=>-3.99970,'lng'=>39.71836,'res'=>7,'kr'=>2],
        // --- Tanzania Regions ---
    ['region_id' => 'dar_cbd', 'name' => 'Dar es Salaam CBD', 'level' => 'cbd', 'lat' => -6.8228, 'lng' => 39.2804, 'res' => 7, 'kr' => 2],
    ['region_id' => 'aru_cbd', 'name' => 'Arusha CBD', 'level' => 'cbd', 'lat' => -3.3667, 'lng' => 36.6833, 'res' => 7, 'kr' => 2],
    ['region_id' => 'mza_cbd', 'name' => 'Mwanza CBD', 'level' => 'cbd', 'lat' => -2.5167, 'lng' => 32.9000, 'res' => 7, 'kr' => 2],
    ['region_id' => 'dod_cbd', 'name' => 'Dodoma CBD', 'level' => 'cbd', 'lat' => -6.1731, 'lng' => 35.7395, 'res' => 7, 'kr' => 2],

    // --- Uganda Regions ---
    ['region_id' => 'kmp_cbd', 'name' => 'Kampala CBD', 'level' => 'cbd', 'lat' => 0.3136, 'lng' => 32.5811, 'res' => 7, 'kr' => 2],
    ['region_id' => 'ebb_cbd', 'name' => 'Entebbe CBD', 'level' => 'cbd', 'lat' => 0.0500, 'lng' => 32.4600, 'res' => 7, 'kr' => 2],
    ['region_id' => 'jin_cbd', 'name' => 'Jinja CBD', 'level' => 'cbd', 'lat' => 0.4390, 'lng' => 33.2032, 'res' => 7, 'kr' => 2],

	];
	    foreach ($regions as $r) {
            $cell = H3Wrapper::latLngToCell($r['lat'], $r['lng'], $r['res']);
            $cells = H3Wrapper::kRing($cell, $r['kr']);
            DB::table('transit_hub_regions')->updateOrInsert(
                ['region_id'=>$r['region_id']],
                [
                    'name'=>$r['name'],
                    'level'=>$r['level'],
                    'h3_res'=>$r['res'],
                    'h3_cells'=>json_encode(array_map('strval',$cells)),
                    'polygon'=>null,
                    'created_at'=>now(),'updated_at'=>now(),
                ]
            );
        }
        $this->info('Seeded demo regions.');
        return 0;
    }
}

