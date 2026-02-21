<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;



class EmailController extends Controller
{
    /**
     * List emails (thread roots only).
     * Replies (parent_id IS NOT NULL) are hidden from the list.
     * Each row includes a thread_count aggregate.
     * Service persons see their own. Super admins can toggle to see all.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Email::query()
            ->whereNull('parent_id') // Only thread roots
            ->withCount('children as reply_count')
            ->latest();

        if ($user->role !== 'super_admin' || $request->query('view_all') !== 'true') {
            $query->where(function ($q) use ($user) {
                $q->where('recipient_email', $user->email)
                    ->orWhere('sender_email', $user->email);
            });
        }

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('sender_email', 'like', "%{$search}%")
                    ->orWhere('recipient_email', 'like', "%{$search}%");
            });
        }

        // Filter by type (incoming/outgoing)
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return $query->paginate(20);
    }

    /**
     * Show a single email and mark it as read.
     */
    public function show(Request $request, Email $email)
    {
        $user = $request->user();

        // access control
        if ($user->role !== 'super_admin') {
            if ($email->recipient_email !== $user->email && $email->sender_email !== $user->email) {
                abort(403);
            }
        }

        if (!$email->read_at && $email->recipient_email === $user->email) {
            $email->update(['read_at' => now()]);
        }

        return $email;
    }

    /**
     * Return the full thread (root + all descendants) for a given email.
     * The provided email can be any message in the thread.
     * Uses a recursive CTE so chains of any depth are supported.
     */
    public function thread(Request $request, Email $email)
    {
        $user = $request->user();

        // ── Step 1: Find the root via an upward recursive CTE ────────────────
        // This resolves the root in one SQL round-trip regardless of chain depth.
        $rootRow = DB::selectOne("
            WITH RECURSIVE ancestors AS (
                SELECT * FROM emails WHERE id = ?
                UNION ALL
                SELECT e.* FROM emails e
                INNER JOIN ancestors a ON e.id = a.parent_id
            )
            SELECT * FROM ancestors WHERE parent_id IS NULL LIMIT 1
        ", [$email->id]);

        if (!$rootRow) {
            abort(404, 'Thread root not found.');
        }

        $root = Email::find($rootRow->id);

        // ── Step 2: Access control ────────────────────────────────────────────
        if ($user->role !== 'super_admin') {
            if ($root->sender_email !== $user->email && $root->recipient_email !== $user->email) {
                abort(403);
            }
        }

        // ── Step 3: Fetch the full downward thread via a recursive CTE ────────
        // One query, any depth.
        $rows = DB::select("
            WITH RECURSIVE thread AS (
                SELECT * FROM emails WHERE id = ?
                UNION ALL
                SELECT e.* FROM emails e
                INNER JOIN thread t ON e.parent_id = t.id
            )
            SELECT * FROM thread ORDER BY created_at ASC
        ", [$root->id]);

        // Hydrate raw stdClass rows into Email models
        $messages = collect($rows)->map(fn($row) => (new Email)->forceFill((array) $row));

        // ── Step 4: Mark all unread incoming messages in this thread as read ──
        $unreadIds = $messages
            ->filter(fn($msg) => !$msg->read_at && $msg->recipient_email === $user->email)
            ->pluck('id');

        if ($unreadIds->isNotEmpty()) {
            Email::whereIn('id', $unreadIds)->update(['read_at' => now()]);
        }

        return response()->json($messages->values());
    }

    /**
     * Send an email (individual or bulk).
     */
    public function store(Request $request)
    {
        $request->validate([
            'subject' => 'required|string',
            'body_html' => 'required|string',
            'recipients' => 'required',
            'parent_id' => 'nullable|exists:emails,id',
            'in_reply_to_message_id' => 'nullable|string',
        ]);

        $user = $request->user();
        $recipients = $request->recipients;
        $parentId = $request->parent_id;
        $inReplyToMessageId = $request->in_reply_to_message_id;

        // Build References chain: parent's references + parent's own message_id
        $references = null;
        if ($parentId) {
            $parentEmail = Email::find($parentId);
            if ($parentEmail) {
                $parts = array_filter([
                    $parentEmail->references,
                    $parentEmail->message_id ?? $inReplyToMessageId,
                ]);
                $references = implode(' ', $parts) ?: null;
            }
        }

        // Handle "Smart Groups"
        if (is_string($recipients)) {
            if (Str::startsWith($recipients, 'group:')) {
                $role = Str::after($recipients, 'group:');
                $recipients = User::where('role', $role)->pluck('email')->toArray();
            } else {
                $recipients = array_map('trim', explode(',', $recipients));
            }
        }

        if (!is_array($recipients)) {
            $recipients = [$recipients];
        }

        $count = 0;
        foreach ($recipients as $recipientEmail) {
            // 1. Send via Resend
            try {
                $apiKey = \Resend\ValueObjects\ApiKey::from(env('RESEND_KEY'));
                $baseUri = \Resend\ValueObjects\Transporter\BaseUri::from('api.resend.com');
                $headers = \Resend\ValueObjects\Transporter\Headers::withAuthorization($apiKey);
                $client = new \GuzzleHttp\Client();
                $transporter = new \Resend\Transporters\HttpTransporter($client, $baseUri, $headers);
                $resend = new \Resend\Client($transporter);

                $emailParams = [
                    'from' => $user->name . ' <' . $user->email . '>',
                    'to' => [$recipientEmail],
                    'subject' => $request->subject,
                    'html' => $request->body_html,
                ];

                // Add threading headers if replying
                if ($inReplyToMessageId) {
                    $emailParams['headers'] = [
                        'In-Reply-To' => $inReplyToMessageId,
                        'References' => $references ?? $inReplyToMessageId,
                    ];
                }

                $result = $resend->emails->send($emailParams);

                // Resend returns an 'id' (UUID) which we use as our message_id reference
                $newMessageId = $result->id ?? null;

            } catch (\Exception $e) {
                \Log::error("Resend API Error: " . $e->getMessage());
                return response()->json(['message' => 'Failed to send email: ' . $e->getMessage()], 500);
            }

            // 2. Store in DB as outgoing
            Email::create([
                'uuid' => Str::uuid(),
                'sender_email' => $user->email,
                'recipient_email' => $recipientEmail,
                'subject' => $request->subject,
                'body_html' => $request->body_html,
                'message_id' => $newMessageId, // ← now persisted
                'type' => 'outgoing',
                'user_id' => $user->id,
                'parent_id' => $parentId,
                'in_reply_to_message_id' => $inReplyToMessageId,
                'references' => $references,
            ]);
            $count++;
        }

        return response()->json(['message' => "Sent {$count} emails successfully."]);
    }
}
