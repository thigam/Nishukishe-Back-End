<?php

namespace App\Http\Controllers;

use App\Models\UserFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserFeedbackController extends Controller
{
    /**
     * Store in-app user feedback.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string',
        ]);

        $feedback = UserFeedback::create([
            'user_id' => $request->user()?->id,
            'name' => $validated['name'] ?? null,
            'email' => $validated['email'] ?? null,
            'subject' => $validated['subject'] ?? 'General Feedback',
            'message' => $validated['message'],
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your feedback!',
            'feedback' => $feedback,
        ], 201);
    }

    /**
     * Get list of feedback for service persons.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');

        $query = UserFeedback::with('user')->orderBy('created_at', 'desc');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $feedbackList = $query->paginate(20);

        return response()->json([
            'success' => true,
            'feedback' => $feedbackList,
        ]);
    }

    /**
     * Mark feedback as resolved/reviewed.
     */
    public function resolve(Request $request, $id): JsonResponse
    {
        $feedback = UserFeedback::findOrFail($id);
        $feedback->update(['status' => 'reviewed']);

        return response()->json([
            'success' => true,
            'message' => 'Feedback marked as reviewed.',
            'feedback' => $feedback,
        ]);
    }

    public function storeContact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        \App\Models\Email::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sender_email' => $validated['email'],
            'recipient_email' => 'support@nishukishe.com',
            'subject' => 'Contact Form Support: ' . $validated['subject'],
            'body_html' => '<h3>Message from ' . e($validated['name']) . '</h3><p>' . nl2br(e($validated['message'])) . '</p>',
            'type' => 'incoming',
        ]);

        return response()->json(['message' => 'Your message has been sent successfully.']);
    }
}
