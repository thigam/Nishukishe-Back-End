<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $email;
    public string $resetUrl;
    /**
     * Create a new message instance.
     */
    public function __construct(string $email, string $resetUrl)
    {
        $this->email = $email;
        $this->resetUrl = $resetUrl;

    }

    public function build()
    {
        $subject = 'Password Reset Request';
        \Log::info('Building PasswordResetLinkSent email for user: ' . $this->email);

        return $this
            ->subject($this->subject)
             ->view('emails.password-reset-link')
            ->with([
                'email' => $this->email,
                'resetUrl' => $this->resetUrl,
            ]);
    }
    
}
