<?php

namespace App\Services\Scalping\Drivers;

class EasyCoachDriver extends BuuPassDriver
{
    public function getProviderName(): string
    {
        return 'easycoach';
    }

    protected function getBookingChannel(): ?string
    {
        return 'easycoach_website';
    }
}
