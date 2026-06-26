<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix two stops whose coordinates were recorded as (0.0, 0.0):
     *   - ST_N0000000_E0000000  "mariakani"   → actual location on Mombasa Rd
     *   - 934                   "Nanyuki bys" → actual Nanyuki town centre
     *
     * These bad coordinates cause massive haversine distance errors in RAPTOR
     * and break the long_distance_fraction filter for ~34 Nairobi–Mombasa routes.
     */
    public function up(): void
    {
        DB::table('stops')
            ->where('stop_id', 'ST_N0000000_E0000000')
            ->update([
                'stop_lat'  => -3.8633,
                'stop_long' => 39.4736,
            ]);

        DB::table('stops')
            ->where('stop_id', '934')
            ->update([
                'stop_lat'  => 0.0062,
                'stop_long' => 37.0740,
            ]);
    }

    public function down(): void
    {
        DB::table('stops')
            ->where('stop_id', 'ST_N0000000_E0000000')
            ->update(['stop_lat' => 0.0, 'stop_long' => 0.0]);

        DB::table('stops')
            ->where('stop_id', '934')
            ->update(['stop_lat' => 0.0, 'stop_long' => 0.0]);
    }
};
