<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class MailgunController extends Controller
{
    public function receive(Request $request)
    {
        // 1. Verification (Basic Signature Check)
// Ideally, verify the Mailgun signature (timestamp, token, signature) using your API key.
// For now, we logging the attempt and proceeding. You should add actual signature verification logic here for security.

        $sender = $request->input('sender');
        $recipient = $request->input('recipient');
        $subject = $request->input('subject');
        $bodyHtml = $request->input('body-html') ?? $request->input('body-plain'); // Fallback
        $messageId = $request->input('Message-Id');

        Log::info("Incoming Email Webhook", [
            'sender' => $sender,
            'recipient' => $recipient,
            'subject' => $subject
        ]);

        // 2. Find associated user if any
// If the sender is a user in our system, maybe we link it?
// Or if the recipient is a user (which they should be, for us to receive it).

        // Logic: Who "owns" this email in our system?
// If it's incoming to 'manager@nishukishe.com', we find the user with that email.
        $user = User::where('email', $recipient)->first();

        Email::create([
            'uuid' => Str::uuid(),
            'sender_email' => $sender,
            'recipient_email' => $recipient,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'message_id' => $messageId,
            'type' => 'incoming',
            'user_id' => $user ? $user->id : null,
            'read_at' => null,
        ]);

        return response()->json(['message' => 'Received'], 200);
    }
}