<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use App\Models\UserRole;
use App\Mail\CommuterWelcomeEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendCommuterWelcomeEmail implements ShouldQueue
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

        if ($user->role === UserRole::USER) {
            Log::info("Queueing CommuterWelcomeEmail for commuter: {$user->email}");
            Mail::to($user->email)->send(new CommuterWelcomeEmail($user));
        }
    }
}
