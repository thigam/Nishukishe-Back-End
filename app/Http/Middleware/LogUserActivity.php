<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\ActivityLog;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class LogUserActivity
{
    /**
     * Handle an incoming request.
     * Just pass request forward; logging happens in terminate().
     */
    public function handle(Request $request, Closure $next)
    {
        // If the table doesn’t exist in test SQLite, skip logging.
        if (!Schema::hasTable('activity_logs')) {
            return $next($request);
        }
        return $next($request);
    }

    /**
     * Terminate middleware: logs activity after response is sent.
     */
    public function terminate(Request $request, $response)
    {
        $agent = new Agent();
        $userId = auth()->id();
        $sessionId = null;

        // Determine session identifier
        if ($userId) {
            // Logged-in users → group by session
            if ($request->hasSession()) {
                $sessionId = $request->session()->getId();
            }
            if (!$sessionId) {
                $sessionId = $request->cookie('activity_session') ?? Str::uuid()->toString();
            }
        } else {
            // Guests → group by IP
            $sessionId = 'ip:' . $request->ip();
        }

        // Find or create activity log entry
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

        // Track visited URLs
        $urls = $log->urls_visited ?? [];
        $currentPath = $request->getPathInfo();
        if (!in_array($currentPath, $urls)) {
            $urls[] = $currentPath;
            $log->urls_visited = $urls;
        }

        // Track multileg route searches
        if ($request->routeIs('multileg.route') && $request->isMethod('post')) {
            $routes = $log->routes_searched ?? [];

            $data = json_decode($response->getContent(), true);
            $hasResults = !empty($data['single_leg']) || !empty($data['multi_leg']);

            $summaries = [];
            foreach (['single_leg', 'multi_leg'] as $bucket) {
                if (empty($data[$bucket]) || !is_array($data[$bucket])) {
                    continue;
                }

                foreach (array_slice($data[$bucket], 0, 3) as $routeIndex => $routeData) {
                    if (!is_array($routeData)) {
                        continue;
                    }

                    $legs = $routeData['legs'] ?? [];
                    $summary = [
                        'type' => $bucket,
                        'legs' => is_array($legs) ? count($legs) : 0,
                    ];

                    $firstLeg = is_array($legs) && isset($legs[0]) && is_array($legs[0]) ? $legs[0] : null;
                    if ($firstLeg) {
                        $firstLegSummary = ['mode' => $firstLeg['mode'] ?? null];
                        if (($firstLeg['mode'] ?? null) === 'bus') {
                            $firstLegSummary['route_id'] = $firstLeg['route_id'] ?? null;
                            $firstLegSummary['sacco_name'] = $firstLeg['sacco_name'] ?? null;
                            $firstLegSummary['route_number'] = $firstLeg['route_number'] ?? null;
                        }
                        $summary['first_leg'] = $firstLegSummary;
                    }

                    if (isset($routeData['summary']) && is_array($routeData['summary'])) {
                        $totalMinutes = $routeData['summary']['total_duration_minutes'] ?? null;
                        if ($totalMinutes !== null) {
                            $summary['total_duration_minutes'] = $totalMinutes;
                        }
                    }

                    $summaries[] = $summary;
                }
            }

            $originLabel = $request->input('origin_label');
            $destinationLabel = $request->input('destination_label');

            if (is_string($originLabel)) {
                $originLabel = trim($originLabel) !== '' ? trim($originLabel) : null;
            }

            if (is_string($destinationLabel)) {
                $destinationLabel = trim($destinationLabel) !== '' ? trim($destinationLabel) : null;
            }

            $routeEntry = [
                'origin' => $request->input('origin'),
                'destination' => $request->input('destination'),
                'origin_label' => $originLabel,
                'destination_label' => $destinationLabel,
                'include_walking' => $request->boolean('include_walking', false),
                'searched_at' => now()->toDateTimeString(),
                'has_results' => $hasResults,
                'route_summaries' => $summaries,
            ];

            $resultSummary = $request->input('result_summary');
            if ($resultSummary !== null) {
                $routeEntry['result_summary'] = $resultSummary;
            }

            $routes[] = $routeEntry;
            $log->routes_searched = $routes;

            // NEW: Also log to the dedicated search_logs table for easier analytics
            try {
                \App\Models\SearchLog::create([
                    'query' => [
                        'origin' => $request->input('origin'),
                        'destination' => $request->input('destination'),
                        'include_walking' => $request->boolean('include_walking', false),
                    ],
                    'has_result' => $hasResults,
                    'source' => 'web_app', // or distinguish mobile if possible via user agent
                    'origin_slug' => $originLabel, // reusing label as slug/name for now
                    'destination_slug' => $destinationLabel,
                ]);
            } catch (\Throwable $e) {
                // Fail silently to not disrupt the request
                \Log::error('Failed to create SearchLog entry: ' . $e->getMessage());
            }
        }

        // Update duration
        $log->ended_at = now();
        $log->duration_seconds = $log->ended_at->diffInSeconds($log->started_at);
        $log->save();

        // Debug info: log to searches.log

        \Log::channel('searches')->info('User searches', [
            // 'user_id'    => $userId,
            // 'email'      => auth()->check() ? auth()->user()->email : 'guest',
            // 'session_id' => $sessionId,
            // 'ip_address' => $request->ip(),
            // 'device'     => $agent->device(),
            // 'browser'    => $agent->browser(),
            // 'url'        => $request->fullUrl(),
            'is_multileg' => $request->routeIs('multileg.route'),
            'has_results' => $hasResults ?? false,
            'origin' => $request->input('origin_label'),
            'destination' => $request->input('destination_label'),
            //log returned data

            'response_data' => $data ?? null,

        ]);

    }
}
