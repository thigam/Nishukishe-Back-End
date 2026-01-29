<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Payment;
use App\Models\Parcel;
use App\Models\Sacco;

class RouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_deploy_logs_is_protected()
    {
        $response = $this->getJson('/api/deploy_logs');
        $response->assertStatus(401);
    }

    public function test_parcels_routes_are_protected()
    {
        $sacco = Sacco::factory()->create();
        $parcel = Parcel::create([
            'sacco_id' => $sacco->sacco_id,
            'tracking_number' => 'PKG-TEST',
            'sender_name' => 'John',
            'sender_phone' => '0700000000',
            'receiver_name' => 'Jane',
            'receiver_phone' => '0711111111',
            'status' => 'registered',
        ]);

        // Index
        $response = $this->getJson('/api/parcels');
        $response->assertStatus(401);

        // Store
        $response = $this->postJson('/api/parcels', [
            'sacco_id' => $sacco->sacco_id,
            'sender_name' => 'John',
            'sender_phone' => '0700000000',
            'receiver_name' => 'Jane',
            'receiver_phone' => '0711111111',
        ]);
        $response->assertStatus(401);

        // Update Status
        $response = $this->patchJson("/api/parcels/{$parcel->id}/status", ['status' => 'in_transit']);
        $response->assertStatus(401);
    }

    public function test_payment_details_route_is_protected()
    {
        $booking = \App\Models\Booking::factory()->create();
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 100,
            'currency' => 'KES',
            'status' => 'pending',
            'provider' => 'mpesa',
            'payment_reference' => 'REF123',
        ]);

        $response = $this->getJson("/api/payments/{$payment->id}");
        $response->assertStatus(401);
    }

    public function test_search_metrics_is_protected()
    {
        $response = $this->getJson('/api/search-metrics');
        $response->assertStatus(401);
    }
}
