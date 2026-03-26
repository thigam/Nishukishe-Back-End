<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SaccoTier;

class SaccoTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'name' => 'Free for Life',
                'price' => 0,
                'is_active' => true,
                'features' => [
                    'route_creation' => true,
                ],
            ],
            [
                'name' => 'Sacco Pro',
                'price' => 3000,
                'is_active' => false,
                'features' => [
                    'route_creation' => true,
                    'analytics' => true,
                    'safaris' => true,
                ],
            ],
            [
                'name' => 'Sacco Premium',
                'price' => 5000,
                'is_active' => false,
                'features' => [
                    'route_creation' => true,
                    'analytics' => true,
                    'priority_support' => true,
                    'parcel_service' => true,
                    'safaris' => true,
                ],
            ],
        ];

        foreach ($tiers as $tier) {
            SaccoTier::updateOrCreate(['name' => $tier['name']], $tier);
        }
    }
}
