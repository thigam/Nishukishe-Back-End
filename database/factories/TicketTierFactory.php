<?php

namespace Database\Factories;

use App\Models\Bookable;
use App\Models\TicketTier;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketTierFactory extends Factory
{
    protected $model = TicketTier::class;

    public function definition(): array
    {
        return [
            'bookable_id' => Bookable::factory(),
            'name' => $this->faker->word,
            'description' => $this->faker->sentence,
            'currency' => 'KES',
            'price' => 1000,
            'service_fee_rate' => 0.05,
            'service_fee_flat' => 0,
            'total_quantity' => 100,
            'remaining_quantity' => 100,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'sales_start' => now(),
            'sales_end' => now()->addDays(2),
            'metadata' => [],
        ];
    }
}
