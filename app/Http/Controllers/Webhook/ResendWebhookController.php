<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Email;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ResendWebhookController extends Controller
{
    public function receive(Request $request)
    {
        // 1. Log Payload for debugging
        $payload = $request->all();
        Log::info('Resend Inbound Webhook:', $payload);

        // 2. Determine Data Source (Root or 'data' key)
        $data = $payload;
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
        }

        // 3. Extract Core Fields
        // Resend sends 'from', 'to' (array), 'subject', 'html', 'text'
        $from = $data['from'] ?? null;
        $tos = $data['to'] ?? [];
        $subject = $data['subject'] ?? '(No Subject)';
        $htmlBody = $data['html'] ?? null;
        $textBody = $data['text'] ?? '';

        if (!$from || empty($tos)) {
            Log::warning("Resend Webhook: Missing 'from' or 'to'", $data);
            return response()->json(['message' => 'Invalid payload'], 200); // 200 to stop retry
        }

        // 4. Normalize 'to' (it should be an array)
        if (!is_array($tos)) {
            $tos = [$tos];
        }

        // 5. Extract Sender Email
        // "Name <email@example.com>" -> "email@example.com"
        $senderEmail = $this->extractEmail($from);

        foreach ($tos as $recipientString) {
            $recipientEmail = $this->extractEmail($recipientString);

            // 6. Find User in DB (who owns this email address?)
            // We look for a user whose email matches the recipient (Internal User)
            // Or maybe the sender is a user? (Less likely for inbound, but possible)
            $user = User::where('email', $recipientEmail)->first();

            // 7. Store Email
            Email::create([
                'uuid' => Str::uuid(),
                'sender_email' => $senderEmail,
                'recipient_email' => $recipientEmail,
                'subject' => $subject,
                // logic: prefer HTML, fallback to newline-to-br text
                'body_html' => $htmlBody ?: nl2br(e($textBody)),
                'message_id' => $data['headers']['Message-ID'] ?? null, // Case sensitive? headers usually lower in array
                'type' => 'incoming',
                'user_id' => $user ? $user->id : null,
                'read_at' => null,
            ]);
        }

        // Verify it's an inbound email event
        // Resend console shows 'email.received', checks 'type' field
        $type = $payload['type'] ?? '';
        if ($type !== 'email.received' && $type !== 'inbound.email_received') {
            // 'inbound.email_received' might be legacy, 'email.received' is current
            // return response()->json(['message' => 'Ignored event type'], 200);
        }

        return response()->json(['message' => 'Processed'], 200);
    }

    private function extractEmail($string)
    {
        if (preg_match('/<([^>]+)>/', $string, $matches)) {
            return $matches[1];
        }
        return trim($string);
    }
}
