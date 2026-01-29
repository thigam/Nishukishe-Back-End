<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\DirectionThread;
use App\Models\Comment;
use Illuminate\Support\Facades\Auth;

class DirectionCommentController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'origin' => 'required|string',
            'destination' => 'required|string',
        ]);

        $thread = DirectionThread::where('origin_slug', $request->origin)
            ->where('destination_slug', $request->destination)
            ->first();

        if (!$thread) {
            return response()->json([]);
        }

        $comments = $thread->comments()
            ->with('author:id,name')
            ->where('status', Comment::STATUS_APPROVED) // Assuming auto-approval or moderation
            ->latest()
            ->paginate(10);

        return response()->json($comments);
    }

    public function store(Request $request)
    {
        $request->validate([
            'origin' => 'required|string',
            'destination' => 'required|string',
            'body' => 'required|string|max:1000',
            'label' => 'nullable|string|in:suggested_change,additional_info,general_comment',
        ]);

        $thread = DirectionThread::firstOrCreate([
            'origin_slug' => $request->origin,
            'destination_slug' => $request->destination,
        ]);

        $comment = $thread->comments()->create([
            'user_id' => Auth::id(), // Can be null now
            'body' => $request->body,
            'label' => $request->label,
            'status' => Comment::STATUS_APPROVED, // Auto-approve for now
        ]);

        return response()->json($comment->load('author:id,name'), 201);
    }
}
