<?php

namespace Database\Factories;

use App\Models\Bookable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookableFactory extends Factory
{
    protected $model = Bookable::class;

    public function definition(): array
    {
        return [
            'organizer_id' => User::factory(),
            'type' => 'tour_event',
            'title' => $this->faker->sentence,
            'subtitle' => $this->faker->sentence,
            'description' => $this->faker->paragraph,
            'status' => 'published',
            'currency' => 'KES',
            'service_fee_rate' => 0.05,
            'service_fee_flat' => 0,
            'published_at' => now(),
            'starts_at' => now()->addDays(1),
            'ends_at' => now()->addDays(2),
            'is_featured' => false,
            'metadata' => [],
        ];
    }
}
