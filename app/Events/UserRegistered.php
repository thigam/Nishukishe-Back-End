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


class UserRegistered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
        \Log::info('UserRegistered event created for user [UserRegistered class]: ' . $user->email);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->user->id),
        ];
    }

    public function broadcastWith()
    {
        \Log::info('Broadcasting UserRegistered event for user: ' . $this->user->email);
        return [
            'name' => $this->user->name,
            'email' => $this->user->email,
        ];
    }
}
