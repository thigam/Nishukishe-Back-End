<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\MpesaCostService;
use Illuminate\Validation\Rule;

class MpesaController extends Controller
{
    public function __construct(private MpesaService $mpesa) {}

    public function generateToken(): string
    {
        return $this->mpesa->accessToken();
    }


    public function stkPush(Request $request)
    {
        Log::info('STK Push Request', $request->all());

        $data = $request->validate([
            'payment_id'        => ['required', 'integer', 'exists:payments,id'],
            'phone'             => ['required', 'regex:/^2547\d{8}$/'],
            'account_reference' => ['nullable', 'string', 'max:20'],
            'description'       => ['nullable', 'string', 'max:64'],
        ]);

        $payment = Payment::with('booking')->findOrFail($data['payment_id']);

        $resp = $this->mpesa->stkPush(
            amount: (string) $payment->amount,
            phone: $data['phone'],
            accountReference: $data['account_reference'] ?? ($payment->booking?->reference ?? 'BOOKING'),
            description: $data['description'] ?? 'Booking payment'
        );

        $payload = $payment->payload ?? [];
        $payload['stk_push'] = [
            'request' => [
                'phone' => $data['phone'],
                'amount' => $payment->amount,
            ],
            'response' => $resp,
        ];

        $payment->fill([
            'provider' => 'mpesa',
            'provider_reference' => $resp['CheckoutRequestID'] ?? $payment->provider_reference,
            'payload' => $payload,
        ]);

        $payment->save();

        return response()->json([
            'ok' => true,
            'message' => $resp['CustomerMessage'] ?? 'Request sent',
            'checkout_request_id' => $payment->provider_reference,
            'payment_id' => $payment->id,
        ]);
    }


    // POST /api/mpesa/callback (set in .env)
    public function callback(Request $request)
    {
        $payload = $request->all();
        Log::info('mpesa Callback', $payload);

        $body = $payload['Body']['stkCallback'] ?? null;
        if (!$body) {
            return response()->json(['status' => 'ignored']);
        }

        $crid = $body['CheckoutRequestID'] ?? null;
        $resultCode = $body['ResultCode'] ?? null;
        $resultDesc = $body['ResultDesc'] ?? '';

        $payment = Payment::where('provider_reference', $crid)->first();
        if ($payment) {
            $payload = $payment->payload ?? [];
            $payload['callback'] = $body;

            $update = [
                'payload' => $payload,
            ];

            if ($payment->provider_reference === null && $crid) {
                $update['provider_reference'] = $crid;
            }

            if ($resultCode === 0 || $resultCode === '0') {
                // Pull out receipt number and amount if present
                $items = collect($body['CallbackMetadata']['Item'] ?? []);
                $amount  = optional($items->firstWhere('Name','Amount'))['Value'] ?? null;

                $update['status'] = 'completed';
                $update['processed_at'] = Carbon::now();

                if ($amount !== null) {
                    $update['amount'] = (float) $amount;
                }

                $payment->fill($update);
                $payment->save();

                if ($payment->booking) {
                    $payment->booking->markAsPaid();
                }
            } else {
                $payload['error'] = $resultDesc;
                $update['payload'] = $payload;
                $update['status'] = 'failed';

                $payment->fill($update);
                $payment->save();
            }
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'OK']); // Acknowledge
    }

    // GET /api/stk/status/{checkoutRequestId}
    public function status(string $checkoutRequestId)
    {
        $payment = Payment::where('provider_reference', $checkoutRequestId)->first();
        if (!$payment) {
            return response()->json(['ok'=>false,'message'=>'Not found'], 404);
        }

        // If still pending, (optionally) query mpesa
        if ($payment->status === 'pending') {
            try {
                $resp = $this->mpesa->queryStkStatus($checkoutRequestId);
                // If mpesa says it’s completed, you may parse & update here.
                // But usually the webhook updates it; leave as-is unless you want active polling.
            } catch (\Throwable $e) {
                // ignore query errors, still pending
            }
        }

        return response()->json([
            'ok' => true,
            'status' => $payment->status,
            'payment' => $payment,
        ]);
    }

    public function b2cPayment(Request $request)
    {
        Log::info('B2C Payment Request', $request->all());

        $data = $request->validate([
            'payment_id' => ['required', 'integer', 'exists:payments,id'],
            'phone'      => ['required', 'regex:/^2547\d{8}$/'],
            'amount'     => ['required', 'numeric', 'min:1'],
            'remarks'    => ['nullable', 'string', 'max:100'],
            'occasion'   => ['nullable', 'string', 'max:50'],
        ]);

        $payment = Payment::findOrFail($data['payment_id']);

        $originatorId = (string) Str::uuid();

        $resp = $this->mpesa->b2cPayment([
            'OriginatorConversationID' => $originatorId,
            'Amount'       => $data['amount'],
            'PartyB'       => $data['phone'],
            'Remarks'      => $data['remarks'] ?? 'Payment disbursement',
            'Occasion'     => $data['occasion'] ?? 'Payout',
        ]);

        $payload = $payment->payload ?? [];
        $payload['b2c'] = [
            'request'  => $data,
            'response' => $resp,
        ];

        $payment->fill([
            'provider'            => 'mpesa-b2c',
            'provider_reference'  => $resp['ConversationID'] ?? $originatorId,
            'status'              => 'processing',
            'payload'             => $payload,
        ]);

        $payment->save();

        return response()->json([
            'ok' => true,
            'message' => $resp['ResponseDescription'] ?? 'B2C request sent',
            'conversation_id' => $resp['ConversationID'] ?? null,
            'payment_id' => $payment->id,
        ]);
    }

    /**
     * Handle B2C Result callback from M-PESA
     */
    public function b2cCallback(Request $request)
    {
        Log::info('B2C Callback', $request->all());

        $result = $request->input('Result');
        if (!$result) {
            return response()->json(['status' => 'ignored']);
        }

        $conversationId = $result['ConversationID'] ?? null;
        $resultCode = $result['ResultCode'] ?? null;
        $resultDesc = $result['ResultDesc'] ?? '';

        $payment = Payment::where('provider_reference', $conversationId)->first();
        if (!$payment) {
            Log::warning('B2C Callback: Payment not found', ['ConversationID' => $conversationId]);
            return response()->json(['status' => 'not found']);
        }

        $payload = $payment->payload ?? [];
        $payload['b2c_callback'] = $result;

        $update = [
            'payload' => $payload,
        ];

        if ($resultCode === 0 || $resultCode === '0') {
            $params = collect($result['ResultParameters']['ResultParameter'] ?? []);

            $receipt = optional($params->firstWhere('Key', 'TransactionReceipt'))['Value'] ?? null;
            $amount  = optional($params->firstWhere('Key', 'TransactionAmount'))['Value'] ?? null;

            $update['status'] = 'completed';
            $update['processed_at'] = Carbon::now();
            $update['provider_reference'] = $receipt ?? $conversationId;

            if ($amount !== null) {
                $update['amount'] = (float) $amount;
            }
        } else {
            $update['status'] = 'failed';
            $payload['error'] = $resultDesc;
        }

        $payment->fill($update)->save();

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Received']);
    }

    /**
     * Handle Queue Timeout for B2C Payments
     */
    public function b2cTimeout(Request $request)
    {
        Log::warning('B2C Timeout', $request->all());
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Timeout received']);
    }

  public function showCost(Request $request, MpesaCostService $mpesaCost)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'type' => [
                'nullable',
                'string',
                Rule::in([
                    'transfer_to_mpesa',
                    'transfer_to_other',
                    'withdraw_from_mpesa',
                    'receiving_to_till',
                    'receiving_from_till',
                    'customer_to_till'
                ]),
            ],
        ]);

        $amount = $validated['amount'];
        $type = $validated['type'] ?? null;

        // 🧮 If type specified → calculate single
        if ($type) {
            if ($type === 'customer_to_till') {
                $cost = $mpesaCost->customerToTill($amount);
            } else {
                $cost = $mpesaCost->calculate($amount, $type);
            }

            return response()->json([
                'amount' => $amount,
                'type' => $type,
                'cost' => $cost,
            ]);
        }

        // 🧮 Otherwise → calculate all
        return response()->json([
            'amount' => $amount,
            'costs' => [
                'transfer_to_mpesa' => $mpesaCost->calculate($amount, 'transfer_to_mpesa'),
                'transfer_to_other' => $mpesaCost->calculate($amount, 'transfer_to_other'),
                'withdraw_from_mpesa' => $mpesaCost->calculate($amount, 'withdraw_from_mpesa'),
                'receiving_to_till' => $mpesaCost->calculate($amount, 'receiving_to_till'),
                'receiving_from_till' => $mpesaCost->calculate($amount, 'receiving_from_till'),
                'customer_to_till' => $mpesaCost->customerToTill($amount),
            ],
        ]);
    }


}
