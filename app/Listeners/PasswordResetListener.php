<?php

namespace App\Listeners;

use App\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\PasswordResetLinkMail;

class PasswordResetListener
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
    public function handle(PasswordResetLinkSent $event): void
    {
        \Log::info('Handling PasswordResetLinkSent event for email: ' . $event->email);
        Mail::to($event->email)->send(new PasswordResetLinkMail($event->email, $event->resetUrl));

    }
}
