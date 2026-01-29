<?php

namespace Database\Factories;

use App\Models\Bookable;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        return [
            'reference' => strtoupper(Str::random(12)),
            'download_token' => Str::random(40),
            'bookable_id' => Bookable::factory(),
            'user_id' => User::factory(),
            'customer_name' => $this->faker->name,
            'customer_email' => $this->faker->email,
            'customer_phone' => $this->faker->phoneNumber,
            'quantity' => 1,
            'currency' => 'KES',
            'total_amount' => 1000,
            'service_fee_amount' => 50,
            'net_amount' => 950,
            'status' => 'pending',
            'payment_status' => 'pending',
            'metadata' => [],
        ];
    }
}
