<?php
namespace App\Http\Controllers;

use App\Mail\TestEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Models\IncomingEmail;
use Illuminate\Support\Carbon;

class MailController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        
        // Set default values. not from request
        $to = 'kniteowl80@gmail.com';
        $subject = 'Test Email Subject';
        $message = 'This is a test email message.';
        
        // Validate the 'to' email
        // $validated = $request->validate([
        //     'to' => 'required|email'
        // ]);
        // return response()->json([
        //     'message' => 'This endpoint is deprecated. Use /send-email instead.',
        //     'status' => 410,
        // ], 410);

        // Send the email without usinga a view or TestEmail class
        Mail::to($to)->send(new TestEmail($subject, $message));
        return response()->json([
            'status' => 'success',
            'message' => 'Email sent successfully',
        ]);
    }

    /*--------------------------------------
    | Get All Emails
    --------------------------------------*/
    public function getAllMails()
    {
        $user = auth('sanctum')->user();

        if(!$user  ) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }



        //1 is approved, 0 is not approved
        if (!$user->is_approved){
            return response()->json(['message' => 'Forbidden'], 403);
        }

        \Log::info('Fetching all emails for user', ['user_id' => $user->id]);

        $mails = IncomingEmail::orderBy('received_at', 'desc')->get();

        return response()->json([
            'message' => 'Emails retrieved successfully',
            'count' => $mails->count(),
            'data' => $mails
        ]);
    }

  /*--------------------------------------
    | Receive and Process Incoming Email
    --------------------------------------*/
    public function receiveEmail(Request $request)
    {
        $rawEmail = $request->input('body-plain') ?? $request->getContent();
        Log::info('Received Email', ['raw_email' => $rawEmail]);

        $to = $request->input('to') ?? optional($request->input('envelope'))['to'] ?? null;
        $subject = $request->input('subject') ?? '(no subject)';
        $from = $request->input('from') ?? $request->input('sender');
        $fromEmail = $this->extractEmail($from);
        $senderName = $this->extractName($from);
        $attachments = $this->processAttachments($request);
        $normalizedSubject = $this->normalizeSubject($subject);

        // Check for existing thread (single envelope)
        $existingThread = IncomingEmail::where('sender', $fromEmail)
            ->where('recipient', $to)
            ->where(function ($q) use ($subject, $normalizedSubject) {
                $q->where('subject', $subject)
                  ->orWhere('subject', $normalizedSubject)
                  ->orWhere('subject', 'like', "%{$normalizedSubject}%");
            })
            ->orderBy('received_at', 'desc')
            ->first();

        $messages = $this->parseEmailThread($rawEmail);
        if (empty($messages)) {
            $messages[] = [
                'from_email' => $fromEmail,
                'from_name' => $senderName,
                'body' => $rawEmail,
                'timestamp' => null
            ];
        }

        if ($existingThread) {
            $this->addMessagesToExistingThread($existingThread, $messages, $fromEmail, $senderName, $to, $subject, $attachments);
            $threadAction = 'updated_existing';
            $threadId = $existingThread->id;
        } else {
            $body = '';
            foreach ($messages as $msg) {
                $body .= $this->formatJournalEntry($msg['body'], $msg['timestamp'], $msg['from_name']) . "\n\n";
            }

            $incomingEmail = new IncomingEmail();
            $incomingEmail->sender = $fromEmail;
            $incomingEmail->sender_name = $senderName;
            $incomingEmail->recipient = $to;
            $incomingEmail->subject = $subject;
            $incomingEmail->body = trim($body);
            $incomingEmail->attachments = json_encode($attachments);
            $incomingEmail->received_at = now();
            $incomingEmail->is_read = false;
            $incomingEmail->save();

            $threadAction = 'created_new';
            $threadId = $incomingEmail->id;
        }

        return response()->json([
            'message' => 'Email thread processed successfully!',
            'thread_action' => $threadAction,
            'thread_id' => $threadId,
            'messages_parsed' => count($messages)
        ]);
    }

    /*--------------------------------------
    | Extract Email / Name
    --------------------------------------*/
    protected function extractEmail(?string $from)
    {
        if (!$from) return null;
        if (preg_match('/<(.+?)>/', $from, $matches)) return strtolower(trim($matches[1]));
        return strtolower(trim($from));
    }

    protected function extractName(?string $from)
    {
        if (!$from) return null;
        if (preg_match('/^(.+?)\s*</', $from, $matches)) return trim($matches[1]);
        $email = $this->extractEmail($from);
        return $email ? explode('@', $email)[0] : $from;
    }

    /*--------------------------------------
    | Process Attachments
    --------------------------------------*/
    protected function processAttachments(Request $request)
    {
        $attachments = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('emails/attachments');
                $attachments[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime' => $file->getClientMimeType(),
                ];
            }
        } elseif ($request->has('attachments')) {
            $attachments = $request->input('attachments');
        }
        return $attachments;
    }

    /*--------------------------------------
    | Email Thread Parsing & Cleaning
    --------------------------------------*/
    protected function parseEmailThread(string $rawBody)
    {
        $messages = [];
        $seenBodies = [];

        $rawBody = str_replace(["\r\n", "\r"], "\n", $rawBody);

        $pattern = '/On\s+([^,]+,\s+\d{1,2}\s+\w+\s+\d{4}\s+at\s+\d{1,2}:\d{2}),\s*([^<]*?)\s*<([^>]+)>\s*wrote:\s*/i';
        preg_match_all($pattern, $rawBody, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) return [[
            'from_email' => 'unknown@example.com',
            'from_name' => 'Unknown',
            'body' => $this->cleanEmailBody($rawBody),
            'timestamp' => null
        ]];

        $matchCount = count($matches[0]);
        for ($i = $matchCount - 1; $i >= 0; $i--) {
            $timestamp = trim($matches[1][$i][0]);
            $fromName = trim($matches[2][$i][0]);
            $fromEmail = trim($matches[3][$i][0]);

            $contentStart = ($i > 0) ? $matches[0][$i - 1][1] + strlen($matches[0][$i - 1][0]) : 0;
            $contentEnd = $matches[0][$i][1];
            $messageBody = substr($rawBody, $contentStart, $contentEnd - $contentStart);
            $messageBody = $this->cleanEmailBody($messageBody);

            if (!empty($messageBody) && !in_array($messageBody, $seenBodies)) {
                $seenBodies[] = $messageBody;
                $messages[] = [
                    'from_email' => $fromEmail,
                    'from_name' => $fromName ?: $this->extractName($fromEmail),
                    'body' => $messageBody,
                    'timestamp' => $timestamp
                ];
            }
        }

        // Handle newest message content
        $lastMatch = $matches[0][0];
        $remainingContent = substr($rawBody, $lastMatch[1] + strlen($lastMatch[0]));
        $remainingContent = $this->cleanEmailBody($remainingContent);
        if (!empty($remainingContent)) {
            $lastFromEmail = trim($matches[3][0][0]);
            $lastFromName = trim($matches[2][0][0]);
            $lastTimestamp = trim($matches[1][0][0]);
            $messages[] = [
                'from_email' => $lastFromEmail,
                'from_name' => $lastFromName ?: $this->extractName($lastFromEmail),
                'body' => $remainingContent,
                'timestamp' => $lastTimestamp
            ];
        }

        return $messages;
    }

    protected function cleanEmailBody(string $body)
    {
        $body = preg_replace('/^>+\s?/m', '', $body);
        $body = preg_replace('/---\s*(Forwarded|Replied)\s*\/?\s*(Forwarded|Replied)?\s*---/i', '', $body);
        $body = preg_replace('/\n\s*\n\s*\n/s', "\n\n", $body);
        $body = preg_replace('/[ \t]{2,}/', ' ', $body);
        return trim($body);
    }

    protected function normalizeSubject(string $subject)
    {
        $subject = preg_replace('/^(Re:|RE:|Fwd:|FW:|Fw:|FWD:)\s*/i', '', $subject);
        $subject = preg_replace('/^\[.*?\]\s*/', '', $subject);
        return trim($subject);
    }

    /*--------------------------------------
    | Add Messages to Existing Thread
    --------------------------------------*/
    protected function addMessagesToExistingThread($existingThread, $messages, $fromEmail, $senderName, $to, $subject, $attachments)
    {
        $currentThreadCount = IncomingEmail::where('sender', $existingThread->sender)
            ->where('recipient', $existingThread->recipient)
            ->where(function($query) use ($existingThread) {
                $normalizedSubject = $this->normalizeSubject($existingThread->subject);
                $query->where('subject', $existingThread->subject)
                      ->orWhere('subject', $normalizedSubject)
                      ->orWhere('subject', 'like', '%' . $normalizedSubject . '%');
            })
            ->count();

        $newEntries = [];
        foreach ($messages as $msg) {
            $body = trim($msg['body']);
            $timestamp = $msg['timestamp'] ?? null;
            if (!empty($body)) {
                $journalEntry = $this->formatJournalEntry($body, $timestamp, $senderName);
                if (!$this->isDuplicateThreadEntry($existingThread, $journalEntry)) {
                    $newEntries[] = $journalEntry;
                }
            }
        }

        if (!empty($newEntries)) {
            $separator = "\n\n" . str_repeat("-", 50) . "\n\n";
            $existingThread->body .= $separator . implode($separator, $newEntries);
            $existingThread->received_at = now();
            $existingThread->is_read = false;
            if (strlen($subject) > strlen($existingThread->subject)) $existingThread->subject = $subject;

            if (!empty($attachments)) {
                $existingAttachments = json_decode($existingThread->attachments, true) ?? [];
                $existingThread->attachments = json_encode(array_merge($existingAttachments, $attachments));
            }

            $existingThread->save();
        }
    }

    protected function formatJournalEntry(string $body, ?string $timestamp, string $senderName)
    {
        $formattedTimestamp = $timestamp ? $this->parseTimestamp($timestamp)->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s');
        return "📧 **{$senderName}** - {$formattedTimestamp}\n\n{$body}";
    }

    protected function isDuplicateThreadEntry($thread, $entry)
    {
        return str_contains($thread->body, $entry);
    }

    protected function parseTimestamp(string $str)
    {
        try {
            return Carbon::parse($str);
        } catch (\Exception $e) {
            return now();
        }
    }

}
