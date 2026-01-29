<?php

namespace App\Listeners;

use App\Events\UserApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use App\Mail\UserApprovedEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;


class SendApprovalEmail
{
    use InteractsWithQueue;

    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(UserApproved $event): void
    {
        $user = $event->user;

        \Log::info('Preparing to send approval email to: ' . $user->email);
        // Send the email using your mailable
        Mail::to($user->email)->send(new UserApprovedEmail($user));

        Log::info('Approval email sent to: ' . $user->email);
    }
}
