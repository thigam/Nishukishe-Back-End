<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

use App\Events\UserRegistered;
use App\Events\UserApproved;
use App\Events\PasswordResetLinkSent;

use App\Listeners\SendVerificationEmail;
use App\Listeners\SendApprovalEmail;
use App\Listeners\PasswordResetListener;
use App\Listeners\SendCommuterWelcomeEmail;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        UserRegistered::class => [
            SendVerificationEmail::class,
            SendCommuterWelcomeEmail::class,
        ],
        PasswordResetLinkSent::class => [
            PasswordResetListener::class,
        ],
        UserApproved::class => [
            SendApprovalEmail::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        parent::boot();
    }
}
