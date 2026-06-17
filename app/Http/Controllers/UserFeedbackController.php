<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UserFeedbackController extends Controller
{
    public function storeContact(Request $request): \Illuminate\Http\JsonResponse
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
