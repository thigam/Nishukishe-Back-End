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
        $from = $data['from'] ?? null;
        $tos = $data['to'] ?? [];
        $subject = $data['subject'] ?? '(No Subject)';
        $htmlBody = $data['html'] ?? null;
        $textBody = $data['text'] ?? '';
        $emailId = $data['email_id'] ?? $data['id'] ?? null;

        if (!$from || empty($tos)) {
            Log::warning("Resend Webhook: Missing 'from' or 'to'", $data);
            return response()->json(['message' => 'Invalid payload'], 200);
        }

        // 4. FETCH CONTENT FALLBACK
        /* 
           Crucial Fix: Use 'emails->receiving->get()' for inbound emails
        */
        if (empty($htmlBody) && empty($textBody) && $emailId) {
            try {
                Log::info("Resend Webhook: Body missing, fetching from API for ID: $emailId");

                // Manual instantiation
                $apiKey = \Resend\ValueObjects\ApiKey::from(env('RESEND_KEY'));
                $baseUri = \Resend\ValueObjects\Transporter\BaseUri::from('api.resend.com');
                $headers = \Resend\ValueObjects\Transporter\Headers::withAuthorization($apiKey);
                $client = new \GuzzleHttp\Client();
                $transporter = new \Resend\Transporters\HttpTransporter($client, $baseUri, $headers);
                $resend = new \Resend\Client($transporter);

                // CORRECT METHOD: emails->receiving->get()
                $email = $resend->emails->receiving->get($emailId);

                $htmlBody = $email->html;
                $textBody = $email->text;

                // Also update headers if they were missing or bare in payload
                // Inbound Email object has full headers usually
                // But for now, let's just focus on body content.

                Log::info("Resend Webhook: Fetched content", ['html_len' => strlen($htmlBody ?? ''), 'text_len' => strlen($textBody ?? '')]);

            } catch (\Exception $e) {
                Log::error("Resend Webhook: Failed to fetch email content: " . $e->getMessage());
            }
        }

        // 5. Normalize 'to'
        if (!is_array($tos)) {
            $tos = [$tos];
        }

        // 6. Extract Sender Email
        $senderEmail = $this->extractEmail($from);

        foreach ($tos as $recipientString) {
            $recipientEmail = $this->extractEmail($recipientString);

            // 7. Find User in DB
            $user = User::where('email', $recipientEmail)->first();

            // 8. Threading Logic
            $headers = $data['headers'] ?? [];
            $inReplyTo = $headers['In-Reply-To'] ?? $headers['in-reply-to'] ?? null;
            $references = $headers['References'] ?? $headers['references'] ?? null;
            $messageId = $headers['Message-ID'] ?? $headers['message-id'] ?? null;

            $parentId = null;
            if ($inReplyTo) {
                $parent = Email::where('message_id', $inReplyTo)->first();
                if ($parent) {
                    $parentId = $parent->id;
                }
            }

            // 9. Body Logic
            $finalHtml = $htmlBody;
            if (empty($finalHtml) && !empty($textBody)) {
                $finalHtml = nl2br(e($textBody));
            }

            // 10. Store Email
            Email::create([
                'uuid' => Str::uuid(),
                'sender_email' => $senderEmail,
                'recipient_email' => $recipientEmail,
                'subject' => $subject,
                'body_html' => $finalHtml,
                'message_id' => $messageId,
                'in_reply_to_message_id' => $inReplyTo,
                'references' => $references,
                'parent_id' => $parentId,
                'type' => 'incoming',
                'user_id' => $user ? $user->id : null,
                'read_at' => null,
            ]);
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
