<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SearchLog;
use App\Models\ActivityLog;
use App\Models\Incident;
use App\Models\User;
use App\Models\UserRole;
use App\Models\SaccoRoute;
use App\Models\Sacco;
use App\Models\VehicleLiveLocation;
use App\Models\Email;
use App\Models\SuggestedRoute;
use App\Mail\AdminDailySummaryEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SendDailySummaryEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'emails:send-daily-summary';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send daily summary metrics email to the super admin.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startOfDay = today()->startOfDay();
        $endOfDay = today()->endOfDay();

        // 1. Number of searches today
        $searches = SearchLog::whereBetween('created_at', [$startOfDay, $endOfDay])->count();

        // 2. Unique visitors today (sessions active/updated today)
        $uniqueVisitors = ActivityLog::whereBetween('updated_at', [$startOfDay, $endOfDay])
            ->whereNotNull('session_id')
            ->where('is_bot', false)
            ->distinct('session_id')
            ->count('session_id');

        $uniqueBots = ActivityLog::whereBetween('updated_at', [$startOfDay, $endOfDay])
            ->whereNotNull('session_id')
            ->where('is_bot', true)
            ->distinct('session_id')
            ->count('session_id');

        // 3. Incidents reported today
        $incidents = Incident::whereBetween('created_at', [$startOfDay, $endOfDay])->count();

        // 4. Signups (Grouped)
        $signups = User::whereBetween('created_at', [$startOfDay, $endOfDay])->get()->groupBy('role');
        $commuters = optional($signups->get(UserRole::USER))->count() ?? 0;
        $saccoMgrs = optional($signups->get(UserRole::SACCO))->count() ?? 0;
        $servicePersons = optional($signups->get(UserRole::SERVICE_PERSON))->count() ?? 0;
        $others = $signups->flatten()->count() - ($commuters + $saccoMgrs + $servicePersons);

        // 5. & 6. Signed In Today (Using ActivityLog joined with User)
        // ActivityLog models active today
        $activityLogs = ActivityLog::with('user')
            ->whereBetween('updated_at', [$startOfDay, $endOfDay])
            ->where('is_bot', false)
            ->get();

        $botActivityLogs = ActivityLog::whereBetween('updated_at', [$startOfDay, $endOfDay])
            ->where('is_bot', true)
            ->get();

        $managersLoggedIn = $activityLogs->filter(function ($log) {
            return $log->user && $log->user->role === UserRole::SACCO;
        })->unique('user_id')->count();

        $servicePersonsLoggedIn = $activityLogs->filter(function ($log) {
            return $log->user && $log->user->role === UserRole::SERVICE_PERSON;
        })->unique('user_id')->count();

        // 7. Sacco routes created today
        $routesCreated = 0;
        if (Schema::hasColumn('sacco_routes', 'created_at')) {
            $routesCreated = SaccoRoute::whereBetween('created_at', [$startOfDay, $endOfDay])->count();
        }

        // 8. Super admin accesses
        $superAdminActivity = [];
        foreach ($activityLogs as $log) {
            $urls = $log->urls_visited ?? [];
            foreach ($urls as $url) {
                // Filter individual page views by timestamp to only include today's hits
                $viewedAtStr = is_array($url) ? ($url['viewed_at'] ?? null) : null;
                if ($viewedAtStr) {
                    try {
                        $viewedAt = \Carbon\Carbon::parse($viewedAtStr);
                        if ($viewedAt->lt($startOfDay) || $viewedAt->gt($endOfDay)) {
                            continue;
                        }
                    } catch (\Exception $e) {
                        // ignore
                    }
                }

                $urlStr = is_array($url) ? ($url['path'] ?? '') : (string) $url;

                if (\Str::contains($urlStr, 'superadmin') || \Str::contains($urlStr, '/api/super-admin/')) {
                    $userName = $log->user ? $log->user->name : "User {$log->user_id}";
                    $superAdminActivity[] = [
                        'user' => $userName,
                        'url' => $urlStr,
                    ];
                }
            }
        }
        $superAdminActivity = collect($superAdminActivity)->unique(function ($item) {
            return $item['user'] . $item['url'];
        })->values()->all();

        // 9. Additional Metrics
        $activeVehicles = VehicleLiveLocation::whereBetween('recorded_at', [$startOfDay, $endOfDay])
            ->distinct('vehicle_id')
            ->count('vehicle_id');

        $newSaccos = Sacco::whereBetween('join_date', [$startOfDay, $endOfDay])->count();

        // 10. Emails received today per nishukishe address
        $receivedEmailsCount = Email::where('type', 'incoming')
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->select('recipient_email', \DB::raw('count(*) as count'))
            ->groupBy('recipient_email')
            ->orderBy('count', 'desc')
            ->get()
            ->map(fn($e) => ['email' => $e->recipient_email, 'count' => $e->count])
            ->toArray();

        // 11. Page Visits (Hits)
        $stageVisits = 0;
        $saccoVisits = 0;
        $directionVisits = 0;
        $blogVisits = 0;
        $discoverVisits = 0;

        foreach ($activityLogs as $log) {
            $urls = $log->urls_visited ?? [];
            foreach ($urls as $url) {
                // Filter individual page views by timestamp to only include today's hits
                $viewedAtStr = is_array($url) ? ($url['viewed_at'] ?? null) : null;
                if ($viewedAtStr) {
                    try {
                        $viewedAt = \Carbon\Carbon::parse($viewedAtStr);
                        if ($viewedAt->lt($startOfDay) || $viewedAt->gt($endOfDay)) {
                            continue;
                        }
                    } catch (\Exception $e) {
                        // ignore
                    }
                }

                $urlStr = is_array($url) ? ($url['path'] ?? '') : (string) $url;
                $urlStr = ltrim($urlStr, '/'); // Normalize

                if (\Str::is(['*stages/*', '*stages'], $urlStr)) {
                    $stageVisits++;
                } elseif (\Str::startsWith($urlStr, 'discover/') && !\Str::contains($urlStr, '/stages')) {
                    $saccoVisits++;
                } elseif ($urlStr === 'discover' || $urlStr === 'discover/') {
                    $discoverVisits++;
                } elseif (\Str::startsWith($urlStr, 'directions/')) {
                    $directionVisits++;
                } elseif (\Str::startsWith($urlStr, 'blog/')) {
                    $blogVisits++;
                }
            }
        }

        $botStageVisits = 0;
        $botSaccoVisits = 0;
        $botDirectionVisits = 0;
        $botBlogVisits = 0;
        $botDiscoverVisits = 0;

        foreach ($botActivityLogs as $log) {
            $urls = $log->urls_visited ?? [];
            foreach ($urls as $url) {
                $viewedAtStr = is_array($url) ? ($url['viewed_at'] ?? null) : null;
                if ($viewedAtStr) {
                    try {
                        $viewedAt = \Carbon\Carbon::parse($viewedAtStr);
                        if ($viewedAt->lt($startOfDay) || $viewedAt->gt($endOfDay)) {
                            continue;
                        }
                    } catch (\Exception $e) {
                        // ignore
                    }
                }

                $urlStr = is_array($url) ? ($url['path'] ?? '') : (string) $url;
                $urlStr = ltrim($urlStr, '/'); // Normalize

                if (\Str::is(['*stages/*', '*stages'], $urlStr)) {
                    $botStageVisits++;
                } elseif (\Str::startsWith($urlStr, 'discover/') && !\Str::contains($urlStr, '/stages')) {
                    $botSaccoVisits++;
                } elseif ($urlStr === 'discover' || $urlStr === 'discover/') {
                    $botDiscoverVisits++;
                } elseif (\Str::startsWith($urlStr, 'directions/')) {
                    $botDirectionVisits++;
                } elseif (\Str::startsWith($urlStr, 'blog/')) {
                    $botBlogVisits++;
                }
            }
        }

        // 12. Suggested routes today
        $suggestedRoutesCount = SuggestedRoute::whereBetween('created_at', [$startOfDay, $endOfDay])->count();

        // 13. Active notifications enabled users (web and mobile)
        $activeWebNotifications = \App\Models\DeviceToken::where('is_active', true)
            ->where('token_type', 'web_push')
            ->count();
        $activeMobileNotifications = \App\Models\DeviceToken::where('is_active', true)
            ->where('token_type', 'fcm')
            ->count();

        // 14. Notifications Sent and Clicked Today
        $notificationsSent = \App\Models\IncidentNotification::whereBetween('created_at', [$startOfDay, $endOfDay])->count();
        $notificationsClicked = \App\Models\IncidentNotification::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->whereNotNull('clicked_at')
            ->count();

        // 15. Search Error Reports (Searches with no results and missing routes)
        $emptySearchesCount = SearchLog::whereBetween('created_at', [$startOfDay, $endOfDay])->where('has_result', false)->count();
        $missingRoutes = SearchLog::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('has_result', false)
            ->get()
            ->map(function ($log) {
                $q = $log->query;
                if (is_string($q)) {
                    $q = json_decode($q, true);
                }

                $origin = 'Unknown';
                if (is_array($q)) {
                    $origVal = $q['origin'] ?? null;
                    if (is_array($origVal)) {
                        $origin = $origVal['name'] ?? $origVal['label'] ?? 'Unknown';
                    } elseif (is_string($origVal)) {
                        $origin = $origVal;
                    }
                }
                if ($origin === 'Unknown') {
                    $origin = $log->origin_slug ?? 'Unknown';
                }

                $destination = 'Unknown';
                if (is_array($q)) {
                    $destVal = $q['destination'] ?? null;
                    if (is_array($destVal)) {
                        $destination = $destVal['name'] ?? $destVal['label'] ?? 'Unknown';
                    } elseif (is_string($destVal)) {
                        $destination = $destVal;
                    }
                }
                if ($destination === 'Unknown') {
                    $destination = $log->destination_slug ?? 'Unknown';
                }

                return "{$origin} ➔ {$destination}";
            })
            ->unique()
            ->values()
            ->toArray();

        // 16. Client-Side Error Logs
        $clientErrorsCount = \App\Models\ClientErrorLog::whereBetween('created_at', [$startOfDay, $endOfDay])->count();
        $clientErrorSamples = \App\Models\ClientErrorLog::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->select('message', \DB::raw('count(*) as count'))
            ->groupBy('message')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get()
            ->toArray();

        // 17. Google Play Clicks Today (from UserActionLog)
        $playStoreClicks = \App\Models\UserActionLog::where('action', 'play_store_click')
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->count();

        // 18. Popup Impressions Today (from UserActionLog)
        $androidPromoImpressions = \App\Models\UserActionLog::where('action', 'android_promo_impression')
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->count();

        $webPushImpressions = \App\Models\UserActionLog::where('action', 'web_push_prompt_impression')
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->count();

        $stats = [
            'searches' => $searches,
            'unique_visitors' => $uniqueVisitors,
            'unique_visitors_bots' => $uniqueBots,
            'incidents' => $incidents,
            'signups' => [
                'commuters' => $commuters,
                'sacco_managers' => $saccoMgrs,
                'service_persons' => $servicePersons,
                'others' => $others,
            ],
            'managers_logged_in' => $managersLoggedIn,
            'service_persons_logged_in' => $servicePersonsLoggedIn,
            'routes_created' => $routesCreated,
            'super_admin_activity' => $superAdminActivity,
            'active_vehicles' => $activeVehicles,
            'new_saccos' => $newSaccos,
            'received_emails_count' => $receivedEmailsCount,
            'page_visits' => [
                'stage_pages' => $stageVisits,
                'sacco_pages' => $saccoVisits,
                'direction_pages' => $directionVisits,
                'blog_pages' => $blogVisits,
                'discover_page' => $discoverVisits,
            ],
            'page_visits_bots' => [
                'stage_pages' => $botStageVisits,
                'sacco_pages' => $botSaccoVisits,
                'direction_pages' => $botDirectionVisits,
                'blog_pages' => $botBlogVisits,
                'discover_page' => $botDiscoverVisits,
            ],
            'suggested_routes_count' => $suggestedRoutesCount,
            'active_web_notifications' => $activeWebNotifications,
            'active_mobile_notifications' => $activeMobileNotifications,
            'notifications_sent' => $notificationsSent,
            'notifications_clicked' => $notificationsClicked,
            'empty_searches_count' => $emptySearchesCount,
            'missing_routes' => $missingRoutes,
            'client_errors_count' => $clientErrorsCount,
            'client_error_samples' => $clientErrorSamples,
            'play_store_clicks' => $playStoreClicks,
            'android_promo_impressions' => $androidPromoImpressions,
            'web_push_impressions' => $webPushImpressions,
        ];

        Mail::to('thigamatthew7@gmail.com')->send(new AdminDailySummaryEmail($stats));

        $this->info('Daily summary email sent successfully.');
        Log::info('Daily summary email sent successfully with stats:', $stats);
    }
}
