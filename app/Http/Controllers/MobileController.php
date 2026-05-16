<?php

namespace App\Http\Controllers;

use App\Models\DeviceSession;
use App\Models\DeviceToken;
use App\Models\LocationPing;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MobileController extends Controller
{
    // ──────────────────────────────────────────────────────────────
    // SESSIONS
    // ──────────────────────────────────────────────────────────────

    /**
     * Record that a user has opened the app.
     * Returns a session_id the client should store and send on close.
     */
    public function sessionStart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id'   => 'required|string|max:64',
            'platform'    => 'nullable|string|max:20',
            'app_version' => 'nullable|string|max:20',
        ]);

        $session = DeviceSession::create([
            'user_id'     => $request->user()?->id, // null for anonymous
            'device_id'   => $validated['device_id'],
            'platform'    => $validated['platform'] ?? 'android',
            'app_version' => $validated['app_version'] ?? null,
            'opened_at'   => now(),
        ]);

        return response()->json(['session_id' => $session->id]);
    }

    /**
     * Mark a session as ended and calculate duration.
     */
    public function sessionEnd(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|integer|exists:device_sessions,id',
        ]);

        $session = DeviceSession::find($validated['session_id']);

        // Only update if not already closed
        if ($session && is_null($session->closed_at)) {
            $session->closed_at        = now();
            $session->duration_seconds = max(0, (int) abs(now()->diffInSeconds($session->opened_at)));
            $session->save();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Periodically update the duration of an open session.
     */
    public function sessionHeartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|integer|exists:device_sessions,id',
        ]);

        $session = DeviceSession::find($validated['session_id']);

        if ($session && is_null($session->closed_at)) {
            $session->duration_seconds = max(0, (int) abs(now()->diffInSeconds($session->opened_at)));
            $session->save();
        }

        return response()->json(['ok' => true]);
    }

    // ──────────────────────────────────────────────────────────────
    // LOCATION PINGS
    // ──────────────────────────────────────────────────────────────

    /**
     * Accept a batch of anonymous location pings.
     * Accepts up to 20 pings per request to allow offline buffering.
     */
    public function locationPing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:64',
            'pings'     => 'required|array|max:20',
            'pings.*.lat'            => 'required|numeric|between:-90,90',
            'pings.*.lng'            => 'required|numeric|between:-180,180',
            'pings.*.accuracy_meters'=> 'nullable|integer|min:0|max:9999',
            'pings.*.speed_kmh'      => 'nullable|integer|min:0|max:500',
            'pings.*.recorded_at'    => 'required|date',
        ]);

        $rows = array_map(fn($p) => [
            'device_id'       => $validated['device_id'],
            'lat'             => $p['lat'],
            'lng'             => $p['lng'],
            'accuracy_meters' => $p['accuracy_meters'] ?? null,
            'speed_kmh'       => $p['speed_kmh'] ?? null,
            'recorded_at'     => $p['recorded_at'],
            'created_at'      => now(),
        ], $validated['pings']);

        LocationPing::insert($rows);

        return response()->json(['ok' => true, 'stored' => count($rows)]);
    }

    // ──────────────────────────────────────────────────────────────
    // DEVICE TOKENS (push notifications)
    // ──────────────────────────────────────────────────────────────

    /**
     * Register or refresh an FCM device token.
     * Uses upsert so re-registrations are idempotent.
     */
    public function registerToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id'  => 'required|string|max:64',
            'token'      => 'required|string',
            'platform'   => 'nullable|string|max:20',
            'token_type' => 'nullable|in:fcm,web_push',
        ]);

        DeviceToken::updateOrCreate(
            ['device_id' => $validated['device_id']],
            [
                'user_id'    => $request->user()?->id,
                'token'      => $validated['token'],
                'platform'   => $validated['platform'] ?? 'android',
                'token_type' => $validated['token_type'] ?? 'fcm',
                'is_active'  => true,
            ]
        );

        return response()->json(['ok' => true]);
    }
}
