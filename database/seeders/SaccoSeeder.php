<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SaccoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $jsonPath = database_path('data/saccos.json');
        if (!file_exists($jsonPath)) {
            $this->command->error("saccos.json not found at {$jsonPath}");
            return;
        }

        $raw        = file_get_contents($jsonPath);
        $saccosData = json_decode($raw, true);

        $inserts = array_map(function ($s) {
            // derive email from first word of sacco name
            $firstName = explode(' ', $s['name'])[0];
            $email     = 'info@' . strtolower($firstName) . '.app';

            return [
                'sacco_id'       => $s['id'],
                'sacco_name'     => $s['name'],
                'vehicle_type'   => 'Bus',
                'join_date'      => now(),
                'sacco_logo'     => null,
                'sacco_location' => null,
                'sacco_phone'    => null,
                'sacco_email'    => $email,
            ];
        }, $saccosData);

        // idempotent: insert new, update existing
        DB::table('saccos')->upsert(
            $inserts,
            ['sacco_id'],
            ['sacco_name','vehicle_type','join_date','sacco_logo','sacco_location','sacco_phone','sacco_email']
        );

        $this->command->info("Upserted " . count($inserts) . " saccos.");
    }
}

