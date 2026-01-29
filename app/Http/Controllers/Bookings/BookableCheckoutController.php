<?php

namespace App\Http\Controllers\Bookings;

use App\Http\Controllers\Controller;
use App\Models\Bookable;
use App\Services\Bookings\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class BookableCheckoutController extends Controller
{
    public function __construct(private readonly BookingService $bookingService)
    {
    }

    public function checkout(Request $request, int $bookableId): JsonResponse
    {
        $bookable = Bookable::where('status', 'published')->findOrFail($bookableId);

        $paymentProvider = env('PAYMENT_PROVIDER', 'jenga'); // Default to jenga for backward compatibility if not set

        $allowedPaymentMethods = [];
        if ($paymentProvider === 'paystack') {
            $allowedPaymentMethods = ['paystack', 'manual'];
        } elseif ($paymentProvider === 'jenga') {
            $allowedPaymentMethods = [
                'jenga',
                'jenga_card',
                'jenga_mobile',
                'manual',
            ];
        } else {
            // Fallback or other providers if added later
            $allowedPaymentMethods = ['manual'];
        }

        $validator = Validator::make($request->all(), [
            'customer_name' => ['required', 'string'],
            'customer_email' => ['required', 'email'],
            'customer_phone' => ['nullable', 'string'],
            'payment_method' => [
                'nullable',
                'string',
                Rule::in($allowedPaymentMethods)
            ],
            'payment_channel' => ['nullable', 'string', Rule::in(['CARD', 'MOBILE'])],
            'tickets' => ['required', 'array', 'min:1'],
            'tickets.*.ticket_tier_id' => ['required', 'integer'],
            'tickets.*.quantity' => ['required', 'integer', 'min:1'],
            'tickets.*.passengers' => ['nullable', 'array'],
            'tickets.*.seat_number' => ['nullable', 'string'],
            'session_id' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            $receivedMethod = $request->input('payment_method', 'null');
            $allowed = implode(', ', $allowedPaymentMethods);
            throw new BadRequestHttpException(
                $validator->errors()->first() . " (Received: {$receivedMethod}, Allowed: {$allowed})"
            );
        }

        $booking = $this->bookingService->createBooking($bookable, $validator->validated());

        return response()->json($booking, 201);
    }

    public function hold(Request $request, int $bookableId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_id' => ['required', 'string'],
            'tickets' => ['required', 'array', 'min:1'],
            'tickets.*.ticket_tier_id' => ['required', 'integer'],
            'tickets.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            throw new BadRequestHttpException($validator->errors()->first());
        }

        $validated = $validator->validated();
        $sessionId = $validated['session_id'];
        $tickets = $validated['tickets'];

        // Clean up expired holds first (lazy cleanup)
        \App\Models\TicketHold::where('expires_at', '<', now())->delete();

        return \Illuminate\Support\Facades\DB::transaction(function () use ($bookableId, $sessionId, $tickets) {
            $holds = [];
            $expiresAt = now()->addMinutes(3);

            foreach ($tickets as $item) {
                $tier = \App\Models\TicketTier::lockForUpdate()->findOrFail($item['ticket_tier_id']);

                if ($tier->bookable_id !== $bookableId) {
                    throw new BadRequestHttpException('Ticket tier does not belong to this bookable.');
                }

                $requestedQuantity = $item['quantity'];

                // Calculate currently held quantity (excluding this session's existing holds if any)
                $currentHeld = \App\Models\TicketHold::where('ticket_tier_id', $tier->id)
                    ->where('session_id', '!=', $sessionId)
                    ->sum('quantity');

                $available = $tier->remaining_quantity - $currentHeld;

                if ($available < $requestedQuantity) {
                    return response()->json([
                        'message' => "Not enough tickets available for {$tier->name}",
                        'tier_id' => $tier->id,
                    ], 409);
                }

                // Create or update hold for this session
                $hold = \App\Models\TicketHold::updateOrCreate(
                    [
                        'ticket_tier_id' => $tier->id,
                        'session_id' => $sessionId,
                    ],
                    [
                        'quantity' => $requestedQuantity,
                        'expires_at' => $expiresAt,
                    ]
                );

                $holds[] = $hold;
            }

            return response()->json([
                'message' => 'Tickets held successfully',
                'expires_at' => $expiresAt->toIso8601String(),
                'holds' => $holds,
            ]);
        });
    }
}
