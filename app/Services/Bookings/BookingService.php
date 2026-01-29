<?php

namespace App\Services\Bookings;

use App\Mail\TicketReceiptMail;
use App\Models\Bookable;
use App\Models\Booking;
use App\Models\TicketTier;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class BookingService
{
    public function __construct(private readonly PaymentGatewayManager $paymentGatewayManager)
    {
    }

    public function createBooking(Bookable $bookable, array $payload): Booking
    {
        $customer = Arr::only($payload, ['customer_name', 'customer_email', 'customer_phone']);
        $ticketsPayload = Arr::get($payload, 'tickets', []);
        [$paymentMethod, $paymentChannel] = $this->normalizePaymentSelection($payload);

        if (empty($customer['customer_name']) || empty($customer['customer_email'])) {
            throw new RuntimeException('Customer name and email are required.');
        }

        if (empty($ticketsPayload)) {
            throw new RuntimeException('At least one ticket tier must be selected.');
        }

        return DB::transaction(function () use ($bookable, $ticketsPayload, $customer, $paymentMethod, $paymentChannel, $payload) {
            $sessionId = Arr::get($payload, 'session_id');
            $totals = $this->calculateTotals($bookable, $ticketsPayload, $sessionId);

            $booking = Booking::create([
                'bookable_id' => $bookable->id,
                'user_id' => Arr::get($payload, 'user_id'),
                'customer_name' => $customer['customer_name'],
                'customer_email' => $customer['customer_email'],
                'customer_phone' => $customer['customer_phone'] ?? null,
                'quantity' => $totals['quantity'],
                'currency' => $bookable->currency,
                'total_amount' => $totals['total'],
                'service_fee_amount' => $totals['service_fee'],
                'net_amount' => $totals['net'],
                'status' => 'pending',
                'payment_status' => 'pending',
                'metadata' => Arr::get($payload, 'metadata'),
            ]);

            $this->issueTickets($booking, $ticketsPayload, $bookable);

            $payment = $booking->payments()->create([
                'provider' => $paymentMethod,
                'channel' => $paymentChannel,
                'status' => 'pending',
                'amount' => $totals['total'],
                'fee_amount' => $totals['service_fee'],
                'payload' => null,
            ]);

            $instructions = $this->paymentGatewayManager->initiatePayment($payment, $payload);

            if (empty($instructions) || ($payment->fresh()->status === 'failed')) {
                throw new RuntimeException('Payment initiation failed. Please try again or contact support.');
            }

            $payment->fill([
                'provider_reference' => Arr::get($instructions, 'reference'),
                'order_reference' => Arr::get($instructions, 'order_reference'),
                'payment_reference' => Arr::get($instructions, 'reference'),
                'payment_link' => Arr::get($instructions, 'payment_link'),
                'channel' => Arr::get($instructions, 'channel', $payment->channel),
                'payload' => $instructions,
            ]);

            $payment->save();

            // Mail::to($booking->customer_email)->send(new TicketReceiptMail($booking->fresh('tickets')));

            return $booking->fresh(['tickets', 'payments']);
        });
    }



    // ... re-writing the method with signature change ...

    protected function calculateTotals(Bookable $bookable, array $ticketsPayload, ?string $sessionId = null): array
    {
        $total = 0;
        $serviceFee = 0;
        $quantity = 0;

        foreach ($ticketsPayload as $item) {
            $tier = TicketTier::lockForUpdate()->findOrFail(Arr::get($item, 'ticket_tier_id'));
            $count = (int) Arr::get($item, 'quantity', 1);

            if ($count < 1) {
                throw new RuntimeException('Quantity must be at least 1.');
            }

            if ($tier->bookable_id !== $bookable->id) {
                throw new RuntimeException('Ticket tier does not belong to this bookable.');
            }

            // Check availability respecting holds
            $heldByOthers = \App\Models\TicketHold::where('ticket_tier_id', $tier->id)
                ->where('expires_at', '>', now())
                ->when($sessionId, fn($q) => $q->where('session_id', '!=', $sessionId))
                ->sum('quantity');

            if (($tier->remaining_quantity - $heldByOthers) < $count) {
                throw new RuntimeException('Not enough inventory for ' . $tier->name);
            }

            // Consume hold if exists
            if ($sessionId) {
                \App\Models\TicketHold::where('ticket_tier_id', $tier->id)
                    ->where('session_id', $sessionId)
                    ->delete();
            }

            $tier->remaining_quantity -= $count;
            $tier->save();

            $price = $tier->price * $count;
            $rate = $tier->service_fee_rate ?? $bookable->service_fee_rate;
            $flat = $tier->service_fee_flat ?? $bookable->service_fee_flat;
            $feePerTicket = ($tier->price * $rate) + $flat;

            $total += $price;
            $serviceFee += $feePerTicket * $count;
            $quantity += $count;
        }

        return [
            'total' => round($total, 2),
            'service_fee' => round($serviceFee, 2),
            'net' => round($total - $serviceFee, 2),
            'quantity' => $quantity,
        ];
    }

    protected function issueTickets(Booking $booking, array $ticketsPayload, Bookable $bookable): void
    {
        foreach ($ticketsPayload as $item) {
            $tier = TicketTier::find(Arr::get($item, 'ticket_tier_id'));
            $count = (int) Arr::get($item, 'quantity', 1);
            $passengers = Arr::get($item, 'passengers', []);

            for ($i = 0; $i < $count; $i++) {
                $passengerData = $passengers[$i] ?? [];
                $booking->tickets()->create([
                    'bookable_id' => $bookable->id,
                    'ticket_tier_id' => $tier?->id,
                    'passenger_name' => Arr::get($passengerData, 'name'),
                    'passenger_email' => Arr::get($passengerData, 'email', $booking->customer_email),
                    'passenger_metadata' => Arr::get($passengerData, 'metadata'),
                    'price_paid' => $tier?->price ?? 0,
                    'seat_number' => Arr::get($item, 'seat_number'),
                ]);
            }
        }
    }

    /**
     * Normalize client-provided payment hints into canonical provider + channel pairs.
     */
    protected function normalizePaymentSelection(array $payload): array
    {
        $method = strtolower((string) Arr::get($payload, 'payment_method', 'jenga'));
        $channel = Arr::get($payload, 'payment_channel');

        if ($channel) {
            $channel = strtoupper((string) $channel);
        }

        if ($method === 'jenga_card') {
            $method = 'jenga';
            $channel = 'CARD';
        } elseif ($method === 'jenga_mobile') {
            $method = 'jenga';
            $channel = 'MOBILE';
        } elseif ($method === 'mpesa') {
            $channel = 'MOBILE';
        }

        return [$method, $channel];
    }
    public function requestRefund(Booking $booking, string $reason): Booking
    {
        if ($booking->status !== 'confirmed') {
            throw new RuntimeException('Only confirmed bookings can be refunded.');
        }

        if ($booking->refund_status) {
            throw new RuntimeException('Refund already requested or processed.');
        }

        $booking->update([
            'refund_status' => 'requested',
            'refund_reason' => $reason,
        ]);

        return $booking;
    }

    public function processRefund(Booking $booking, bool $approved): Booking
    {
        if ($booking->refund_status !== 'requested') {
            throw new RuntimeException('Booking does not have a pending refund request.');
        }

        if (!$approved) {
            $booking->update(['refund_status' => 'rejected']);
            return $booking;
        }

        // Calculate 80% refund
        $refundAmount = round($booking->total_amount * 0.80, 2);

        // In a real scenario, we would trigger the payment gateway refund here.
        // For now, we simulate it.

        $booking->update([
            'refund_status' => 'approved',
            'refund_amount' => $refundAmount,
            'status' => 'refunded', // Or keep as confirmed but mark refund? Let's say refunded.
        ]);

        // Create a negative payment record or refund record if needed
        $booking->payments()->create([
            'provider' => 'system',
            'channel' => 'REFUND',
            'status' => 'success',
            'amount' => -$refundAmount,
            'description' => 'Refund processed (80% of total)',
        ]);

        return $booking;
    }
}
