<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private string $username;
    private string $apiKey;
    private string $senderId;

    public function __construct()
    {
        $this->username = config('services.africastalking.username', 'sandbox');
        $this->apiKey = config('services.africastalking.api_key', '');
        $this->senderId = config('services.africastalking.sender_id', '');
    }

    public function sendTicketSms(string $phoneNumber, string $ticketNumber, string $saccoName, string $route, string $time)
    {
        $message = "Booking Confirmed! Ticket: $ticketNumber. $saccoName - $route. Departs: $time. Show this SMS to board. Safe travels!";

        return $this->send($phoneNumber, $message);
    }

    public function send(string $to, string $message)
    {
        if (empty($this->apiKey)) {
            Log::warning("SMS Service: API Key missing. Skipping SMS to $to");
            return false;
        }

        try {
            $response = Http::asForm()
                ->withHeaders([
                        'apiKey' => $this->apiKey,
                        'Accept' => 'application/json',
                    ])
                ->post('https://api.africastalking.com/version1/messaging', [
                        'username' => $this->username,
                        'to' => $to,
                        'message' => $message,
                        'from' => $this->senderId ?: null, // Optional sender ID
                    ]);

            if ($response->successful()) {
                Log::info("SMS sent to $to: " . $response->body());
                return true;
            } else {
                Log::error("SMS failed to $to: " . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("SMS Exception: " . $e->getMessage());
            return false;
        }
    }
}
