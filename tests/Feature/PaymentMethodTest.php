<?php

namespace Tests\Feature;

use App\Models\Bookable;
use App\Models\TicketTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_mpesa_when_provider_is_paystack()
    {
        // Mock env to return paystack
        // Note: env() calls might be cached or hard to mock in some setups, 
        // but config() is better. However the controller uses env().
        // We might need to set the env var in phpunit.xml or runtime.
        // For this test, let's try setting it via putenv or config if the app uses config.
        // The controller uses env('PAYMENT_PROVIDER'), which is tricky to mock at runtime if config is cached.
        // But let's try.

        // Actually, the controller reads env() directly. 
        // We can try to override it.

        // Create a bookable
        $bookable = Bookable::factory()->create(['status' => 'published']);
        $tier = TicketTier::factory()->create(['bookable_id' => $bookable->id]);

        $payload = [
            'customer_name' => 'Test User',
            'customer_email' => 'test@example.com',
            'customer_phone' => '254712345678',
            'payment_method' => 'mpesa',
            'payment_channel' => 'MOBILE',
            'tickets' => [
                [
                    'ticket_tier_id' => $tier->id,
                    'quantity' => 1,
                ]
            ],
            'session_id' => 'test-session',
        ];

        // Force the environment variable for this test
        // This might not work if config is cached, but let's see.
        // A better approach would be if the controller used config('services.payment.provider')
        // But we are testing existing code.

        // We can't easily mock env() in a running app unless we use specific test helpers or if the app re-reads it.
        // Laravel's env() helper returns from $_ENV or getenv().

        // Let's assume we can't easily change the env for the *application* process if it's already bootstrapped,
        // but for a feature test, we are in the same process.

        // However, the controller logic:
        // $paymentProvider = env('PAYMENT_PROVIDER', 'jenga');

        // We can try setting the env var.
        $originalEnv = env('PAYMENT_PROVIDER');
        putenv('PAYMENT_PROVIDER=paystack');

        $response = $this->postJson(route('bookings.checkout', $bookable), $payload);

        putenv("PAYMENT_PROVIDER=$originalEnv"); // Restore

        $response->assertStatus(400);
        $this->assertStringContainsString('The selected payment method is invalid.', $response->json('message'));
    }

    public function test_it_accepts_paystack_when_provider_is_paystack()
    {
        $bookable = Bookable::factory()->create(['status' => 'published']);
        $tier = TicketTier::factory()->create(['bookable_id' => $bookable->id]);

        $payload = [
            'customer_name' => 'Test User',
            'customer_email' => 'test@example.com',
            'customer_phone' => '254712345678',
            'payment_method' => 'paystack',
            'payment_channel' => 'MOBILE', // or CARD
            'tickets' => [
                [
                    'ticket_tier_id' => $tier->id,
                    'quantity' => 1,
                ]
            ],
            'session_id' => 'test-session',
        ];

        $originalEnv = env('PAYMENT_PROVIDER');
        putenv('PAYMENT_PROVIDER=paystack');

        // We need to mock the BookingService to avoid actual booking logic/DB calls that might fail or be complex
        $this->mock(\App\Services\Bookings\BookingService::class, function ($mock) {
            $booking = new \App\Models\Booking([
                'id' => 1,
                'status' => 'pending',
                'reference' => 'REF123',
                'total_amount' => 1000,
                'currency' => 'KES',
            ]);
            $booking->id = 1; // Manually set ID as it's not persisted
            $mock->shouldReceive('createBooking')->andReturn($booking);
        });

        $response = $this->postJson(route('bookings.checkout', $bookable), $payload);

        putenv("PAYMENT_PROVIDER=$originalEnv");

        $response->assertStatus(201);
    }
}
