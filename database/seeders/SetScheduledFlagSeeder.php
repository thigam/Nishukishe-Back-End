<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SetScheduledFlagSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('sacco_routes')
            ->whereIn('sacco_route_id', function ($query) {
                $query->select('sacco_route_id')->from('trips');
            })
            ->update(['scheduled' => true]);
    }
}

