<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class TestEmail extends Mailable
{
    public string $subjectLine;
    public string $bodyText; // ✅ renamed from $message

    public function __construct(string $subjectLine, string $bodyText)
    {
        $this->subjectLine = $subjectLine;
        $this->bodyText = $bodyText;
    }

    public function build()
    {
        return $this
            ->subject($this->subjectLine)
            ->view('emails.test')
            ->with([
                'subject' => $this->subjectLine,
                'body' => $this->bodyText, // ✅ new key
            ]);
    }
}
