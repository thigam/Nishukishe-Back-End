<?php

namespace App\Listeners;
use App\Events\UserRegistered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Mail\RegistrationEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class SendVerificationEmail
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
        $subjectLine = 'Welcome to Nishukishe';
        $bodyText = 'Thank you for registering with us! Please verify your email address in 5 minutes to complete the registration process.';
        $temporaryUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(5),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $parsed = parse_url($temporaryUrl);
        $query = [];
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $verificationUrl = $frontendUrl . '/verify-email/' . $user->id . '/' . sha1($user->email)
            . '?expires=' . ($query['expires'] ?? '')
            . '&signature=' . ($query['signature'] ?? '');

        $bodyText .= "\n\nClick here to verify your email: " . $verificationUrl;

        // Send a custom welcome or verification confirmation email

        Mail::to($user->email)->send(new RegistrationEmail($user, $subjectLine, $bodyText));

    }
}