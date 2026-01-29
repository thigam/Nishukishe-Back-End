<?php

namespace Database\Factories;

use App\Models\TembeaOperatorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TembeaOperatorProfileFactory extends Factory
{
    protected $model = TembeaOperatorProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_name' => $this->faker->company,
            'contact_name' => $this->faker->name,
            'contact_email' => $this->faker->companyEmail,
            'contact_phone' => $this->faker->phoneNumber,
            'public_email' => $this->faker->companyEmail,
            'public_phone' => $this->faker->phoneNumber,
            'status' => 'active',
            'metadata' => [],
        ];
    }
}
