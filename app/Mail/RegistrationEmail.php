<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $subjectLine;
    public $bodyText;

    public function __construct($user, $subjectLine, $bodyText)
    {
        $this->user = $user;
        $this->subjectLine = $subjectLine;
        $this->bodyText = $bodyText;
    }

    public function build()
    {

        \Log::info('Building RegistrationEmail for user: ' . $this->user->email);
        
        return $this
            ->subject($this->subjectLine)
            ->view('emails.test')
            ->with([
                'subject' => $this->subjectLine,
                'body' => $this->bodyText, // ✅ new key
            ]);
    }
}
