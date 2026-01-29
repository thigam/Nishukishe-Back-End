<?php

namespace App\Http\Controllers;

use App\Services\PaystackService;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackController extends Controller
{
    protected PaystackService $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    public function verify(Request $request)
    {
        $request->validate([
            'reference' => 'required|string',
        ]);

        $reference = $request->input('reference');

        try {
            $data = $this->paystackService->verifyTransaction($reference);

            if (($data['status'] ?? '') === 'success') {
                // Find payment by reference (assuming reference matches booking reference or payment id stored in metadata)
                // In PaymentGatewayManager, we set reference to booking reference.
                // However, multiple payments might exist for a booking.
                // Ideally we should have stored payment_id in metadata and Paystack returns it.
                
                $paymentId = $data['metadata']['payment_id'] ?? null;
                
                if ($paymentId) {
                    $payment = Payment::find($paymentId);
                    if ($payment && $payment->status !== 'completed') {
                        $payment->update([
                            'status' => 'completed',
                            'transaction_reference' => $reference,
                            'payload' => array_merge($payment->payload ?? [], ['paystack_response' => $data]),
                            'paid_at' => now(),
                        ]);
                        
                        // Trigger any booking status updates if needed
                        if ($payment->booking) {
                            $payment->booking->markAsPaid();
                        }
                    }
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            Log::error('Paystack verification error', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
