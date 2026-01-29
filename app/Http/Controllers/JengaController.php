<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class JengaController extends Controller
{
    public function show(Payment $payment, \App\Services\PaystackService $paystackService)
    {
        if ($payment->provider === 'paystack' && $payment->status === 'pending') {
            try {
                $data = $paystackService->verifyTransaction($payment->provider_reference);
                if (($data['status'] ?? '') === 'success') {
                    $payment->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                        'payload' => array_merge($payment->payload ?? [], ['paystack_verification' => $data]),
                    ]);

                    if ($payment->booking) {
                        $payment->booking->markAsPaid();
                    }
                } elseif (($data['status'] ?? '') === 'failed') {
                    $payment->update(['status' => 'failed']);
                }
            } catch (\Exception $e) {
                // Log error but return current status
                Log::error('Paystack lazy verification failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'ok' => true,
            'status' => $payment->status,
            'payment' => $payment,
        ]);
    }

    public function callback(Request $request)
    {
        $payload = $request->all();

        Log::info('Jenga callback received', [
            'payload' => $payload,
            'headers' => $request->headers->all(),
        ]);

        // --- Extract references -------------------------------------------------

        $paymentReference = $request->input('paymentReference')
            ?? Arr::get($payload, 'payment.reference')
            ?? Arr::get($payload, 'transaction.paymentReference');

        $orderReference = $request->input('orderReference')
            ?? Arr::get($payload, 'order.orderReference')
            ?? Arr::get($payload, 'transaction.orderReference');

        $transactionReference = $request->input('transactionReference')
            ?? Arr::get($payload, 'transactionReference')
            ?? Arr::get($payload, 'transaction.reference');

        // --- Extract status bits ------------------------------------------------

        // text-like status if present (e.g. "SUCCESS", "FAILED")
        $rawStatus = $request->input('transactionStatus')
            ?? Arr::get($payload, 'transactionStatus')
            ?? Arr::get($payload, 'transaction.status');

        // boolean status (true/false) from Jenga
        $boolStatus = $request->input('status');
        $code = $request->input('code') ?? Arr::get($payload, 'code');

        // Normalise status:
        //  - status true or code 3  -> SUCCESS
        //  - status false or code != 3 (non-empty) -> FAILED
        if (empty($rawStatus)) {
            if ($boolStatus === true || $boolStatus === 'true' || (string) $boolStatus === '1') {
                $rawStatus = 'SUCCESS';
            } elseif ((string) $code === '3') {
                // Jenga STK: code 3 = "Transaction Successful - Settled"
                $rawStatus = 'SUCCESS';
            } elseif ($boolStatus === false || $boolStatus === 'false' || (string) $boolStatus === '0') {
                $rawStatus = 'FAILED';
            } elseif ($code !== null && (string) $code !== '' && (string) $code !== '3') {
                // Any non-empty code that isn't 3 we treat as a failure
                $rawStatus = 'FAILED';
            }
        }

        $status = strtoupper((string) $rawStatus);

        Log::info('Jenga callback parsed refs', [
            'paymentReference' => $paymentReference,
            'orderReference' => $orderReference,
            'transactionReference' => $transactionReference,
            'rawStatus' => $rawStatus,
            'status' => $status,
            'code' => $code,
        ]);

        // --- Ensure we have at least one reference -----------------------------

        if (!$paymentReference && !$orderReference && !$transactionReference) {
            Log::warning('Jenga callback with no usable references', [
                'payload' => $payload,
            ]);

            return response()->json(['ok' => false, 'message' => 'No reference provided'], 400);
        }

        // --- Find the payment by any of the refs -------------------------------

        $query = Payment::query();

        if ($paymentReference) {
            $query->orWhere('payment_reference', $paymentReference)
                ->orWhere('provider_reference', $paymentReference);
        }

        if ($orderReference) {
            $query->orWhere('order_reference', $orderReference);
        }

        if ($transactionReference) {
            $query->orWhere('payment_reference', $transactionReference)
                ->orWhere('provider_reference', $transactionReference);
        }

        $payment = $query->first();

        if (!$payment) {
            Log::warning('Jenga callback payment not found', [
                'paymentReference' => $paymentReference,
                'orderReference' => $orderReference,
                'transactionReference' => $transactionReference,
            ]);

            return response()->json(['ok' => false, 'message' => 'Payment not found'], 404);
        }

        // --- Build update payload ----------------------------------------------

        $existingPayload = $payment->payload ?? [];
        $existingPayload['jenga_callback'] = $payload;

        $update = [
            'payload' => $existingPayload,
            'provider' => 'jenga',
            'payment_reference' => $paymentReference
                ?? $transactionReference
                ?? $payment->payment_reference,
            'order_reference' => $orderReference ?? $payment->order_reference,
            'provider_reference' => $payment->provider_reference
                ?? $paymentReference
                ?? $transactionReference
                ?? $payment->provider_reference,
        ];

        $receipt = $request->input('receiptNumber')
            ?? Arr::get($payload, 'transaction.receiptNumber')
            ?? Arr::get($payload, 'receiptNumber');

        // --- Apply status -------------------------------------------------------

        if (in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'PAID'], true)) {
            $update['status'] = 'completed';
            $update['processed_at'] = Carbon::now();
            $update['receipt_number'] = $receipt ?? $payment->receipt_number;

            Log::info('Jenga callback marking payment completed', [
                'payment_id' => $payment->id,
                'update' => $update,
            ]);

            $payment->fill($update)->save();

            if ($payment->booking) {
                $payment->booking->markAsPaid();
            }
        } elseif (in_array($status, ['FAILED', 'FAIL', 'ERROR', 'DECLINED'], true)) {
            $update['status'] = 'failed';

            Log::info('Jenga callback marking payment failed', [
                'payment_id' => $payment->id,
                'update' => $update,
            ]);

            $payment->fill($update)->save();
        } else {
            Log::info('Jenga callback without clear status, updating payload only', [
                'payment_id' => $payment->id,
            ]);

            $payment->fill($update)->save();
        }

        return response()->json(['ok' => true]);
    }

}
