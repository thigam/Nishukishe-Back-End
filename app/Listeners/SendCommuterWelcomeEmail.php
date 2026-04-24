<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use App\Models\UserRole;
use App\Mail\CommuterWelcomeEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendCommuterWelcomeEmail
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(UserRegistered $event): void
    {
        $user = $event->user;
        Log::info("SendCommuterWelcomeEmail listener handling user: {$user->email}, role: {$user->role}");

        if ($user->role === UserRole::USER) {
            Log::info("Conditions met. Sending CommuterWelcomeEmail to: {$user->email}");
            try {
                Mail::to($user->email)->send(new CommuterWelcomeEmail($user));
                Log::info("CommuterWelcomeEmail sent successfully to: {$user->email}");
            } catch (\Exception $e) {
                Log::error("Failed to send CommuterWelcomeEmail to: {$user->email}. Error: " . $e->getMessage());
            }
        } else {
            Log::info("Conditions NOT met. User role is '{$user->role}', expected '" . UserRole::USER . "'");
        }
    }
}
