<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\H3Wrapper;

$points = [
    'ars_cbd' => [-3.37, 36.68],
    'msh_cbd' => [-3.34, 37.34],
    'eld_cbd' => [0.51, 35.27],
    'ksm_cbd' => [-0.10, 34.75],
    'bsa_brd' => [0.46, 34.11],
    'mlb_brd' => [0.63, 34.27],
    'nmg_brd' => [-2.54, 36.78],
    'ubn_trm' => [-6.79, 39.21],
    'jnj_cbd' => [0.43, 33.20],
    'mbr_cbd' => [-0.61, 30.65],
    'nmy_bpk' => [0.31, 32.57],
];

foreach ($points as $id => $p) {
    $cell = H3Wrapper::latLngToCell($p[0], $p[1], 7);
    $ring = H3Wrapper::kRing($cell, 1);
    echo "'$id' => " . json_encode($ring) . ",\n";
}
