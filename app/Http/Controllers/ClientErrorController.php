<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\ClientErrorLog;
use Illuminate\Http\JsonResponse;

class ClientErrorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'stack' => 'nullable|string',
            'url' => 'nullable|string',
            'user_agent' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $log = ClientErrorLog::create([
            'user_id' => auth('sanctum')->id(),
            ...$validated,
            'user_agent' => $validated['user_agent'] ?? $request->userAgent(),
        ]);

        return response()->json($log, 201);
    }

    public function index(Request $request): JsonResponse
    {
        $logs = ClientErrorLog::with('user')
            ->latest()
            ->paginate(20);

        return response()->json($logs);
    }
}
