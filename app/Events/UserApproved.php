<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;


class UserApproved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
        Log::info('UserApproved event created for user: ' . $user->email);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->user->id),
        ];
    }

    public function broadcastWith(): array
    {
        Log::info('Broadcasting UserApproved event for user: ' . $this->user->email);
        return [
            'name'  => $this->user->name,
            'email' => $this->user->email,
        ];
    }
}
