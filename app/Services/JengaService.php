<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class JengaService
{
    private string $baseUrl;
    private string $authUrl;
    private string $apiKey;
    private string $merchantCode;
    private string $merchantName;
    private string $consumerSecret;
    private string $privateKeyPath;
    private string $callbackUrl;
    private ?string $equityAccount;
    private string $countryCode;
    private string $defaultTelco;
    private string $cardRedirectUrl;
    private string $customerPostalCode;

    public function __construct()
    {
        $this->baseUrl          = rtrim(config('jenga.base_url'), '/');
        $this->authUrl          = config('jenga.auth_url');
        $this->apiKey           = config('jenga.api_key');
        $this->merchantCode     = config('jenga.merchant_code');
        $this->merchantName     = config('jenga.merchant_name');
        $this->consumerSecret   = config('jenga.consumer_secret');
        $this->privateKeyPath   = config('jenga.private_key_path');
        $this->callbackUrl      = config('jenga.callback_url');
        $this->equityAccount    = config('jenga.equity_account_number');
        $this->countryCode      = config('jenga.country_code', 'KE');
        $this->defaultTelco     = config('jenga.default_telco', 'Safaricom');
        $this->cardRedirectUrl  = config('jenga.card_redirect_url');
        $this->customerPostalCode = config('jenga.customer_postal_code', '00100');
    }

    /**
     * Fetch and cache Jenga JWT token
     */
    public function accessToken(): string
    {
        return Cache::remember('jenga_access_token', 10 * 60, function () {
            $response = Http::withHeaders([
                'Api-Key'      => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->authUrl, [
                'merchantCode'   => $this->merchantCode,
                'consumerSecret' => $this->consumerSecret,
            ]);

            Log::info('Jenga auth response', [
                'status' => $response->status(),
                'body'   => $response->json() ?? $response->body(),
            ]);

            $response->throw();

            if (! isset($response['accessToken'])) {
                throw new RuntimeException('Jenga auth response missing accessToken.');
            }

            return $response['accessToken'];
        });
    }

    /**
     * Basic RSA-SHA256 signature generator.
     */
    public function sign(string $dataToSign): string
    {
        $privateKey = @file_get_contents($this->privateKeyPath);

        if (! $privateKey) {
            throw new RuntimeException("Unable to read Jenga private key at {$this->privateKeyPath}");
        }

        $keyResource = openssl_pkey_get_private($privateKey);

        if (! $keyResource) {
            throw new RuntimeException('Invalid Jenga private key.');
        }

        $signature = '';
        openssl_sign($dataToSign, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        openssl_free_key($keyResource);

        return base64_encode($signature);
    }

    /**
     * Normalize a Kenyan mobile number to 2547XXXXXXXX format.
     */
    protected function normalizeMsisdn(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone ?? '');

        if (str_starts_with($digits, '0')) {
            $digits = '254' . substr($digits, 1);
        } elseif (str_starts_with($digits, '7') && strlen($digits) === 9) {
            $digits = '254' . $digits;
        } elseif (str_starts_with($digits, '254') && strlen($digits) === 12) {
            // fine
        }

        return $digits;
    }

    /**
     * CARD flow: Create Payment Link via API for card payments.
     *
     * This follows the Payment Link as API docs:
     * - Body: customers[], paymentLink{}, notifications[]
     * - Signature: expiryDate + amount + currency + amountOption + externalRef
     */
    public function initiateCardPayment(array $data): array
    {
        $token = $this->accessToken();

        $amount   = (float) $data['amount'];
        $currency = $data['currency'] ?? 'KES';
        $externalRef = $data['paymentReference']; // your unique ref for this payment

        $expiryDate = now()->addDay()->format('Y-m-d');
        $saleDate   = now()->format('Y-m-d');

        $payload = [
            'customers' => [[
                'firstName'       => $data['customer_name'],
                'lastName'        => '',
                'email'           => $data['customer_email'],
                'phoneNumber'     => $this->normalizeMsisdn($data['customer_phone']),
                'countryCode'     => $this->countryCode,
                'postalOrZipCode' => $this->customerPostalCode,
            ]],
            'paymentLink' => [
                'expiryDate'      => $expiryDate,
                'saleDate'        => $saleDate,
                'paymentLinkType' => 'SINGLE',
                'saleType'        => 'SERVICE',
                'name'            => $data['description'] ?? 'Ticket purchase',
                'description'     => $data['description'] ?? 'Ticket purchase',
                'externalRef'     => $externalRef,
                'paymentLinkRef'  => '',
                'redirectURL'     => $data['redirect_url'] ?? $this->cardRedirectUrl,
                'amountOption'    => 'RESTRICTED',
                'amount'          => $amount,
                'currency'        => $currency,
            ],
            'notifications' => ['EMAIL', 'SMS'],
        ];

        $dataToSign = $payload['paymentLink']['expiryDate']
            . $payload['paymentLink']['amount']
            . $payload['paymentLink']['currency']
            . $payload['paymentLink']['amountOption']
            . $payload['paymentLink']['externalRef'];

        $signature = $this->sign($dataToSign);

        $url = $this->baseUrl . '/api-checkout/api/v1/create/payment-link';

        Log::info('Jenga Payment Link request', ['url' => $url, 'payload' => $payload]);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Api-Key'       => $this->apiKey,
            'Signature'     => $signature,
            'Content-Type'  => 'application/json',
        ])->post($url, $payload);

        Log::info('Jenga Payment Link response', [
            'status' => $response->status(),
            'body'   => $response->json() ?? $response->body(),
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * M-Pesa STK Push – account-based settlement.
     *
     * Follows the docs:
     * - Body: merchant{}, payment{}
     * - Signature:
     *   merchant.accountNumber
     *   + payment.ref
     *   + payment.mobileNumber
     *   + payment.telco
     *   + payment.amount
     *   + payment.currency
     */
    public function mpesaStkPushAccountBased(array $data): array
    {
        $token = $this->accessToken();

        if (! $this->equityAccount && empty($data['equity_account_number'])) {
            throw new RuntimeException('Jenga Equity account number is not configured.');
        }

        $accountNumber = $data['equity_account_number'] ?? $this->equityAccount;
        $amount = number_format((float) $data['amount'], 2, '.', '');
        $currency = $data['currency'] ?? 'KES';
        $mobile = $this->normalizeMsisdn($data['customer_phone']);
        $telco  = $data['telco'] ?? $this->defaultTelco;
        $ref    = $data['paymentReference']; // must be 6–12 chars as per docs

        $payload = [
            'merchant' => [
                'accountNumber' => $accountNumber,
                'countryCode'   => $this->countryCode,
                'name'          => $this->merchantName,
            ],
            'payment' => [
                'ref'          => $ref,
                'amount'       => (string) $amount,
                'currency'     => $currency,
                'telco'        => $telco,
                'mobileNumber' => $mobile,
                'date'         => now()->format('Y-m-d'),
                'callBackUrl'  => $data['callback_url'] ?? $this->callbackUrl,
                'pushType'     => 'STK',
            ],
        ];

        $dataToSign = $payload['merchant']['accountNumber']
            . $payload['payment']['ref']
            . $payload['payment']['mobileNumber']
            . $payload['payment']['telco']
            . $payload['payment']['amount']
            . $payload['payment']['currency'];

        $signature = $this->sign($dataToSign);

        $url = $this->baseUrl . '/v3-apis/payment-api/v3.0/stkussdpush/initiate';

        Log::info('Jenga STK request', ['url' => $url, 'payload' => $payload]);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Api-Key'       => $this->apiKey,
            'Signature'     => $signature,
            'Content-Type'  => 'application/json',
        ])->post($url, $payload);

        Log::info('Jenga STK response', [
            'status' => $response->status(),
            'body'   => $response->json() ?? $response->body(),
        ]);

        $response->throw();

        return $response->json();
    }
public function mpesaStkPushWalletBased(array $data): array
{
    $token = $this->accessToken();

    // required pieces
    $orderReference  = $data['orderReference'];      // from PaymentGatewayManager
    $paymentReference = $data['paymentReference'];   // also from PaymentGatewayManager
    $amount          = (float) $data['amount'];
    $currency        = $data['currency'] ?? 'KES';
    $customerName    = $data['customer_name'];
    $customerEmail   = $data['customer_email'];
    $customerPhone   = $data['customer_phone'];
    $description     = $data['description'] ?? 'Ticket purchase';
    $callbackUrl     = $data['callback_url'] ?? $this->callbackUrl;

    // Jenga wallet-based STK payload (PGW style)
    $payload = [
        'order' => [
            'orderReference' => $orderReference,
            'orderAmount'    => $amount,
            'orderCurrency'  => $currency,
            'source'         => 'APICHECKOUT',
            'countryCode'    => config('jenga.country_code', 'KE'),
            'description'    => $description,
        ],
        'customer' => [
            'name'           => $customerName,
            'email'          => $customerEmail,
            'phoneNumber'    => $customerPhone,
            'identityNumber' => $data['customer_id_number'] ?? '00000000',
        ],
        'payment' => [
            'paymentReference' => $paymentReference,
            'paymentCurrency'  => $currency,
            'channel'          => 'MOBILE',
            'service'          => 'MPESA',
            'provider'         => 'JENGA',
            'callbackUrl'      => $callbackUrl,
            'details' => [
                'msisdn'         => $customerPhone,
                'paymentAmount'  => $amount,
            ],
        ],
    ];

    // Signature: check Jenga docs for the exact canonical string.
    // Common pattern for wallet-based STK:
    $dataToSign = $orderReference . $currency . $customerPhone . (string) $amount;
    $signature  = $this->sign($dataToSign);

    $url = $this->baseUrl . '/api-checkout/mpesa-stk-push/v3.0/init';

    \Log::info('Jenga wallet STK request', ['url' => $url, 'payload' => $payload]);

    $response = Http::withHeaders([
        'Authorization' => "Bearer {$token}",
        'Api-Key'       => $this->apiKey,
        'Signature'     => $signature,
        'Content-Type'  => 'application/json',
    ])->post($url, $payload);

    \Log::info('Jenga wallet STK response', [
        'status' => $response->status(),
        'body'   => $response->json() ?? $response->body(),
    ]);

    $response->throw();

    return $response->json();
}

}

