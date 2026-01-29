<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;


class PasswordResetLinkSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $email;
    public string $resetUrl;

    /**
     * Create a new event instance.
     */
    public function __construct(string $email, string $resetUrl)
    {
       $this->email = $email; 
        $this->resetUrl = $resetUrl;
    }

    public function broadcastOn(): array
    {
        \Log::info('PasswordResetLinkSent event created for email: ' . $this->email);
       
        return [
            new PrivateChannel('password-reset.' . $this->email),
        ];
    }
    public function broadcastWith()
    {
        \Log::info('Broadcasting PasswordResetLinkSent event for email: ' . $this->email);
        return [
            'email' => $this->email,
            'resetUrl' => $this->resetUrl,
        ];
    }
}
