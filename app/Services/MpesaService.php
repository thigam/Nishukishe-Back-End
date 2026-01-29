<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MpesaService
{
    private string $base;
    private string $key;
    private string $secret;
    private string $shortcode;
    private string $passkey;
    private string $callback;
    private string $baseUrl;

    public function __construct()
    {
        $this->base = config('mpesa.base_url');
        $this->key = config('mpesa.consumer_key');
        $this->secret = config('mpesa.consumer_secret');
        $this->shortcode = config('mpesa.shortcode');
        $this->passkey = config('mpesa.passkey');
        $this->callback = config('mpesa.callback_url');
        $this->baseUrl = config('mpesa.base_url');
    }

    public function accessToken(): string
    {
        $resp = Http::withBasicAuth($this->key, $this->secret)
            ->get($this->base.'/oauth/v1/generate?grant_type=client_credentials');
        $resp->throw();
        return $resp['access_token'];
    }

    public function stkPush(string $amount, string $phone, string $accountReference, string $description)
    {
        $timestamp = now()->format('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

        $body = [
            "BusinessShortCode" => $this->shortcode,
            "Password"          => $password,
            "Timestamp"         => $timestamp,
            "TransactionType"   => "CustomerPayBillOnline",
            "Amount"            => $amount,
            "PartyA"            => $phone,
            "PartyB"            => $this->shortcode,
            "PhoneNumber"       => $phone,
            "CallBackURL"       => route('mpesa.callback'),
            "AccountReference"  => $accountReference,
            "TransactionDesc"   => $description,
        ];

        // 👉 Add Authorization header with token
        $response = Http::withToken($this->accessToken())
            ->post("{$this->baseUrl}/mpesa/stkpush/v1/processrequest", $body);

        \Log::info('STK Request', $body);
        \Log::info('STK Response', $response->json());

        if (!$response->ok()) {
            throw new \Exception("STK Push failed: " . $response->body());
        }

        return $response->json();
    }

    public function queryStkStatus(string $checkoutRequestId): array
    {
        $timestamp = now()->format('YmdHis');
        $password = base64_encode($this->shortcode.$this->passkey.$timestamp);

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        $token = $this->accessToken();
        $resp = Http::withToken($token)
            ->post($this->base.'/mpesa/stkpushquery/v1/query', $payload);
        $resp->throw();
        return $resp->json();
    }

    public function b2cPayment(array $data)
    {
        $endpoint = $this->baseUrl . '/mpesa/b2c/v3/paymentrequest';

        $body = [
            'OriginatorConversationID' => $data['OriginatorConversationID'],
            'InitiatorName' => config('mpesa.b2c_initiator_name'),
            'SecurityCredential' => $this->encryptCredential(config('mpesa.b2c_initiator_password')),
            'CommandID' => 'BusinessPayment', // or SalaryPayment / PromotionPayment
            'Amount' => $data['Amount'],
            'PartyA' => config('mpesa.shortcode'),
            'PartyB' => $data['PartyB'],
            'Remarks' => $data['Remarks'] ?? 'Payment disbursement',
            'QueueTimeOutURL' => route('mpesa.b2c.timeout'),
            'ResultURL' => route('mpesa.b2c.callback'),
            'Occasion' => $data['Occasion'] ?? 'Payout',
        ];

        $token = $this->accessToken();

        try {
            $httpResponse = Http::withToken($token)
                ->acceptJson()
                ->post($endpoint, $body);
        } catch (ConnectionException $exception) {
            throw new \RuntimeException(
                'M-PESA B2C request failed: '.$exception->getMessage(),
                0,
                $exception
            );
        }

        Log::info('B2C API Response', [
            'status' => $httpResponse->status(),
            'body' => $httpResponse->json() ?? $httpResponse->body(),
        ]);

        if ($httpResponse->failed()) {
            $message = $this->extractErrorMessage($httpResponse) ?? 'M-PESA B2C request failed.';

            throw new \RuntimeException($message, $httpResponse->status());
        }

        return $httpResponse->json();
    }

    private function extractErrorMessage(Response $response): ?string
    {
        $body = $response->json();

        if (is_array($body)) {
            $candidates = [
                Arr::get($body, 'errorMessage'),
                Arr::get($body, 'fault.detail.errorMessage'),
                Arr::get($body, 'ResponseDescription'),
                Arr::get($body, 'errorDescription'),
                Arr::get($body, 'errorCode'),
            ];

            foreach ($candidates as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }

            $encoded = json_encode($body);
            if ($encoded !== false) {
                return $encoded;
            }
        }

        $rawBody = $response->body();

        if (is_string($rawBody) && trim($rawBody) !== '') {
            return trim($rawBody);
        }

        return null;
    }

    private function encryptCredential(string $plainPassword): string
    {
        $certPath = storage_path('certificates/SandboxCertificate.cer');
        $publicKey = file_get_contents($certPath);

        $keyResource = openssl_pkey_get_public($publicKey);
        if (!$keyResource) {
            throw new \Exception("Invalid M-Pesa public key: Could not load certificate from {$certPath}");
        }

        openssl_public_encrypt($plainPassword, $encrypted, $keyResource, OPENSSL_PKCS1_PADDING);
        openssl_free_key($keyResource);

        return base64_encode($encrypted);
    }


}
