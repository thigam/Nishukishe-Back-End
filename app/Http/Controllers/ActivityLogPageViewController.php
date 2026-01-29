<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;

class ActivityLogPageViewController extends Controller
{
    public function storeDirections(Request $request): JsonResponse
    {
        return $this->recordPageView(
            request: $request,
            normalisePath: fn (string $path) => $this->normaliseDirectionsPath($path),
            source: 'directions-frontend',
            rejectionMessage: 'Only directions page views can be recorded via this endpoint.',
        );
    }

    public function storeDiscover(Request $request): JsonResponse
    {
        return $this->recordPageView(
            request: $request,
            normalisePath: fn (string $path) => $this->normaliseDiscoverPath($path),
            source: 'discover-frontend',
            rejectionMessage: 'Only discover sacco or stage page views can be recorded via this endpoint.',
        );
    }

    private function resolveActivityLog(Request $request, Agent $agent): array
    {
        $userId = auth()->id();
        $sessionId = null;
        $shouldSetCookie = false;

        if ($request->hasSession()) {
            $sessionId = $request->session()->getId();
        }

        if (! $sessionId) {
            $sessionId = $request->cookie('activity_session');
        }

        if (! $sessionId) {
            $sessionId = Str::uuid()->toString();
            $shouldSetCookie = true;
        }

        $log = ActivityLog::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'user_id' => $userId,
                'ip_address' => $request->ip(),
                'device' => $agent->device() ?: ($agent->isDesktop() ? 'Desktop' : 'Unknown'),
                'browser' => $agent->browser() ?: 'Unknown',
                'started_at' => now(),
            ]
        );

        if ($log->user_id === null && $userId) {
            $log->user_id = $userId;
        }

        if ($log->ip_address === null) {
            $log->ip_address = $request->ip();
        }

        if (! $log->device) {
            $log->device = $agent->device() ?: ($agent->isDesktop() ? 'Desktop' : 'Unknown');
        }

        if (! $log->browser) {
            $log->browser = $agent->browser() ?: 'Unknown';
        }

        if (! $log->started_at) {
            $log->started_at = now();
        }

        return [$log, $sessionId, $shouldSetCookie];
    }

    private function normaliseDirectionsPath(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            $path = $value;
        }

        if ($path === '') {
            return null;
        }

        $path = '/' . ltrim($path, '/');

        if (! Str::startsWith($path, ['/directions', '/direction'])) {
            return null;
        }

        if (
            (Str::startsWith($path, '/direction/') || $path === '/direction')
            && ! Str::startsWith($path, '/directions')
        ) {
            $suffix = Str::of($path)->after('/direction');
            $path = (string) Str::of('/directions' . $suffix)->replaceMatches('/\/\/+/', '/');
        }

        return $path;
    }

    private function normaliseDiscoverPath(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            $path = $value;
        }

        if ($path === '') {
            return null;
        }

        $path = '/' . ltrim($path, '/');

        if (! Str::startsWith($path, '/discover')) {
            return null;
        }

        return $path;
    }

    private function recordPageView(
        Request $request,
        callable $normalisePath,
        string $source,
        string $rejectionMessage,
    ): JsonResponse {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
        ]);

        $path = $normalisePath($data['path'] ?? '');

        if ($path === null) {
            return response()->json([
                'message' => $rejectionMessage,
            ], 422);
        }

        $agent = new Agent();
        $agent->setUserAgent($request->userAgent() ?? '');

        [$log, $sessionId, $shouldSetCookie] = $this->resolveActivityLog($request, $agent);

        $urls = $log->urls_visited ?? [];
        $urls[] = [
            'path' => $path,
            'source' => $source,
            'viewed_at' => now()->toIso8601String(),
        ];
        $log->urls_visited = array_values($urls);
        $log->ended_at = now();

        if ($log->started_at) {
            $log->duration_seconds = $log->ended_at?->diffInSeconds($log->started_at) ?? null;
        }

        $log->save();

        $response = response()->json(['status' => 'ok']);

        if ($shouldSetCookie) {
            $minutes = (int) config('session.lifetime', 120);
            $cookie = cookie(
                'activity_session',
                $sessionId,
                max($minutes, 60 * 24),
                '/',
                config('session.domain'),
                $request->isSecure(),
                true,
                false,
                config('session.same_site', 'lax')
            );
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
