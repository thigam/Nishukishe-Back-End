<?php

namespace Database\Factories;

use App\Models\Sacco;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SaccoFactory extends Factory
{
    protected $model = Sacco::class;

    public function definition(): array
    {
        return [
            'sacco_id' => (string) Str::uuid(),
            'sacco_name' => $this->faker->company . ' Sacco',
            'vehicle_type' => 'matatu',
            'join_date' => now(),
            'sacco_location' => $this->faker->city,
            'sacco_phone' => $this->faker->phoneNumber,
            'sacco_email' => $this->faker->companyEmail,
            'is_approved' => true,
        ];
    }
}
