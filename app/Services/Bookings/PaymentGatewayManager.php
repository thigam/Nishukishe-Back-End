<?php

namespace App\Services\Bookings;

use App\Models\Payment;
use App\Services\JengaService;
use App\Services\MpesaService;
use App\Services\PaystackService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentGatewayManager
{
    public function __construct(private readonly Container $container)
    {
    }

    public function initiatePayment(Payment $payment, array $payload): array
    {
        $defaultProvider = env('PAYMENT_DEFAULT_PROVIDER', 'jenga');
        $method = Arr::get($payload, 'payment_method', $defaultProvider);

        if ($method === 'manual' && !config('services.bookings.allow_manual_checkout', true)) {
            throw new RuntimeException('Manual payment option is disabled.');
        }

        return match ($method) {
            'mpesa' => $this->handleMpesa($payment, $payload),
            'jenga' => $this->handleJenga($payment, $payload),
            'manual' => $this->handleManual($payment),
            'dpo' => $this->handleThirdParty('DPO Group', $payment),
            'paystack' => $this->handlePaystack($payment, $payload),
            default => throw new RuntimeException('Unsupported payment method: ' . $method),
        };
    }


    protected function handleJenga(Payment $payment, array $payload): array
    {
        $payment->loadMissing('booking');

        $channel = strtoupper(Arr::get($payload, 'payment_channel', 'MOBILE'));
        $customerPhone = Arr::get($payload, 'customer_phone', $payment->booking?->customer_phone);
        $customerEmail = Arr::get($payload, 'customer_email', $payment->booking?->customer_email);
        $customerName = Arr::get($payload, 'customer_name', $payment->booking?->customer_name);

        if (!$customerEmail || !$customerName) {
            throw new RuntimeException('Customer details are required for Jenga payments.');
        }

        if (!$customerPhone) {
            throw new RuntimeException('Customer phone number is required for Jenga payments.');
        }

        $orderReference = $payment->booking?->reference ?? ('BOOKING-' . $payment->booking?->id);

        // paymentReference for Jenga (6–20 alphanumeric, we use 10 chars)
        $paymentReference = 'P' . Str::upper(Str::random(9)); // e.g. PRIDEGDOWV

        $telco = config('jenga.default_telco', 'Safaricom');

        $jenga = $this->container->make(JengaService::class);

        $requestPayload = [
            'orderReference' => $orderReference, // for your own tracking + card payments
            'paymentReference' => $paymentReference, // will be used as STK ref
            'equity_account_number' => config('jenga.equity_account_number'),
            'amount' => $payment->amount,
            'currency' => $payment->booking?->currency ?? 'KES',
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'description' => $payment->description ?? 'Booking payment',
            'callback_url' => config('jenga.callback_url'),
            'telco' => $telco,
            'redirect_url' => config('jenga.card_redirect_url'),
        ];

        // CARD → Payment Link, MOBILE → STK push
        $response = $channel === 'CARD'
            ? $jenga->initiateCardPayment($requestPayload)
            : $jenga->mpesaStkPushWalletBased($requestPayload);

        // For STK, Jenga responds with:
        // { status: true, code: -1, message: "...", reference: "PRIDEGDOWV", transactionId: "PRIDEGDOWV" }
        // For card, it may respond with paymentLink + order/payment refs.
        $transactionRef = $response['transactionReference']
            ?? $response['reference']
            ?? $response['paymentReference']
            ?? $paymentReference;

        $orderRefFromResponse = $response['orderReference'] ?? $orderReference;

        return [
            'provider' => 'jenga',
            'channel' => $channel,
            'request' => $requestPayload,
            'response' => $response,
            'reference' => $transactionRef,          // <- this is what gets stored as payment_reference/provider_reference
            'order_reference' => $orderRefFromResponse,
            'payment_link' => $channel === 'CARD'
                ? ($response['paymentLink']
                    ?? ($response['links']['payment'] ?? null)
                    ?? ($response['redirectUrl'] ?? null))
                : null,
        ];
    }

    protected function handleMpesa(Payment $payment, array $payload): array
    {
        $payment->loadMissing('booking');
        $phone = Arr::get($payload, 'customer_phone', $payment->booking?->customer_phone);

        if (!$phone) {
            throw new RuntimeException('Phone number is required for M-Pesa payments.');
        }

        $mpesaService = $this->container->make(MpesaService::class);

        $response = $mpesaService->stkPush(
            amount: (string) $payment->amount,
            phone: $phone,
            accountReference: $payment->booking?->reference ?? 'BOOKING',
            description: $payment->description ?? 'Booking payment'
        );

        return [
            'provider' => 'mpesa',
            'request' => [
                'phone' => $phone,
                'amount' => $payment->amount,
            ],
            'response' => $response,
            'reference' => $response['CheckoutRequestID'] ?? null,
        ];
    }

    protected function handleThirdParty(string $provider, Payment $payment): array
    {
        $payment->loadMissing('booking');

        return [
            'provider' => $provider,
            'instructions' => sprintf('%s checkout session created for %.2f %s', $provider, $payment->amount, $payment->booking?->currency ?? 'KES'),
            'reference' => $payment->booking?->reference,
        ];
    }

    protected function handleManual(Payment $payment): array
    {
        $payment->loadMissing('booking');

        return [
            'provider' => 'manual',
            'instructions' => 'Booking recorded without upfront payment. Collect payment manually.',
            'reference' => $payment->booking?->reference,
        ];
    }
    protected function handlePaystack(Payment $payment, array $payload): array
    {
        $payment->loadMissing('booking');

        $customerEmail = Arr::get($payload, 'customer_email', $payment->booking?->customer_email);
        $amount = $payment->amount * 100; // Paystack expects amount in kobo/cents

        if (!$customerEmail) {
            throw new RuntimeException('Customer email is required for Paystack payments.');
        }

        $paystackService = $this->container->make(PaystackService::class);

        $channel = strtoupper(Arr::get($payload, 'payment_channel', ''));
        $customerPhone = Arr::get($payload, 'customer_phone', $payment->booking?->customer_phone);

        // If it's a mobile payment, we try to trigger a direct charge (STK Push)
        if ($channel === 'MOBILE' && $customerPhone) {
            $formattedPhone = $this->normalizePhoneNumber($customerPhone);

            $data = [
                'email' => $customerEmail,
                'amount' => $amount,
                'currency' => $payment->booking?->currency ?? 'KES',
                'mobile_money' => [
                    'phone' => $formattedPhone,
                    'provider' => 'mpesa'
                ],
                'reference' => $payment->booking?->reference ?? Str::uuid()->toString(),
                'metadata' => [
                    'booking_id' => $payment->booking?->id,
                    'custom_fields' => [
                        [
                            'display_name' => 'Booking Reference',
                            'variable_name' => 'booking_reference',
                            'value' => $payment->booking?->reference,
                        ],
                    ],
                ],
            ];

            try {
                $response = $paystackService->charge($data);

                // Response for charge usually contains a reference and status
                // For M-Pesa, it might be 'pending' or 'send_otp' (rare for M-Pesa) or 'success'

                return [
                    'provider' => 'paystack',
                    'request' => $data,
                    'response' => $response,
                    'reference' => $response['reference'],
                    'payment_link' => null, // No redirect needed for STK push
                ];
            } catch (\Exception $e) {
                // Fallback to standard checkout if direct charge fails?
                // Or just rethrow. Let's log and fall back to initialize for safety, 
                // or just let it fail if the user specifically wanted STK.
                // For now, let's throw to be explicit.
                throw $e;
            }
        }

        // Default: Standard Checkout Page
        $callbackUrl = Arr::get($payload, 'callback_url', config('services.paystack.callback_url'));

        $data = [
            'email' => $customerEmail,
            'amount' => $amount,
            'reference' => $payment->booking?->reference ?? Str::uuid()->toString(),
            'callback_url' => $callbackUrl,
            'currency' => $payment->booking?->currency ?? 'KES',
            'metadata' => [
                'payment_id' => $payment->id,
                'booking_reference' => $payment->booking?->reference,
                'customer_phone' => $customerPhone,
                'channel' => $channel,
            ],
        ];

        if ($channel === 'CARD') {
            $data['channels'] = ['card'];
        }

        $response = $paystackService->initializeTransaction($data);

        return [
            'provider' => 'paystack',
            'request' => $data,
            'response' => $response,
            'reference' => $response['reference'],
            'payment_link' => $response['authorization_url'],
        ];
    }

    protected function normalizePhoneNumber(string $phone): string
    {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // If starts with 0, replace with 254
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }

        // Ensure it starts with +
        return '+' . $phone;
    }
}
