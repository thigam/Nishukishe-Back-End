<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UserApprovedEmail extends Mailable 
{
    use Queueable, SerializesModels;

    public User $user;
    public ?string $temporaryPassword;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, ?string $temporaryPassword = null)
    {
        $this->user = $user;
        $this->temporaryPassword = $temporaryPassword;

        Log::info('UserApprovedEmail instance created for user: ' . $user->email);
    }

    /**
     * Build the message.
     */
    public function build()
    {
        Log::info('Building UserApprovedEmail for user: ' . $this->user->email);
        $email= $this->user->email;
        $token = $this->user->createToken('sacco_admin_token')->plainTextToken;
        $frontendUrl = config('app.frontend_url') . '/saccos/signup/first-sacco-manager' . '?email=' . urlencode($email). '&token=' . urlencode($token);

        return $this
            ->subject('Your Account Has Been Approved!')
            ->view('emails.sacco-approved')
            ->with([
                'user' => $this->user,
                'frontendUrl' => $frontendUrl,
            ]);
    }
}
