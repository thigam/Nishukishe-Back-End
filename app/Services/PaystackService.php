<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaystackService
{
    private string $secretKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
        $this->baseUrl = config('services.paystack.payment_url');
    }

    /**
     * Initialize a transaction
     */
    public function initializeTransaction(array $data): array
    {
        $url = "{$this->baseUrl}/transaction/initialize";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->post($url, $data);

        if ($response->failed()) {
            Log::error('Paystack initialization failed', [
                'response' => $response->json(),
                'data' => $data
            ]);
            throw new RuntimeException('Paystack payment initialization failed: ' . $response->json('message', 'Unknown error'));
        }

        return $response->json('data');
    }

    /**
     * Verify a transaction
     */
    public function verifyTransaction(string $reference): array
    {
        $url = "{$this->baseUrl}/transaction/verify/{$reference}";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->get($url);

        if ($response->failed()) {
            Log::error('Paystack verification failed', [
                'response' => $response->json(),
                'reference' => $reference
            ]);
            throw new RuntimeException('Paystack payment verification failed: ' . $response->json('message', 'Unknown error'));
        }

        return $response->json('data');
    }
    /**
     * Charge a customer directly (e.g. Mobile Money)
     */
    public function charge(array $data): array
    {
        $url = "{$this->baseUrl}/charge";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->post($url, $data);

        if ($response->failed()) {
            Log::error('Paystack charge failed', [
                'response' => $response->json(),
                'data' => $data
            ]);
            throw new RuntimeException('Paystack charge failed: ' . $response->json('message', 'Unknown error') . ' | Data: ' . json_encode($data));
        }

        return $response->json('data');
    }

    /**
     * Create a Transfer Recipient
     */
    public function createTransferRecipient(array $data): array
    {
        $url = "{$this->baseUrl}/transferrecipient";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->post($url, $data);

        if ($response->failed()) {
            Log::error('Paystack create recipient failed', [
                'response' => $response->json(),
                'data' => $data
            ]);
            throw new RuntimeException('Paystack create recipient failed: ' . $response->json('message', 'Unknown error'));
        }

        return $response->json('data');
    }

    /**
     * Initiate a Transfer
     */
    public function initiateTransfer(array $data): array
    {
        $url = "{$this->baseUrl}/transfer";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->post($url, $data);

        if ($response->failed()) {
            Log::error('Paystack transfer failed', [
                'response' => $response->json(),
                'data' => $data
            ]);
            throw new RuntimeException('Paystack transfer failed: ' . $response->json('message', 'Unknown error'));
        }

        return $response->json('data');
    }

    /**
     * Finalize a Transfer (OTP)
     */
    public function finalizeTransfer(array $data): array
    {
        $url = "{$this->baseUrl}/transfer/finalize_transfer";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->post($url, $data);

        if ($response->failed()) {
            Log::error('Paystack finalize transfer failed', [
                'response' => $response->json(),
                'data' => $data
            ]);
            throw new RuntimeException('Paystack finalize transfer failed: ' . $response->json('message', 'Unknown error'));
        }

        return $response->json('data');
    }
}
