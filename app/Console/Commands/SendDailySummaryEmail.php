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
        $today = today();

        // 1. Number of searches today
        $searches = SearchLog::whereDate('created_at', $today)->count();

        // 2. Unique visitors today
        $uniqueVisitors = ActivityLog::whereDate('started_at', $today)
            ->whereNotNull('session_id')
            ->distinct('session_id')
            ->count('session_id');

        // 3. Incidents reported today
        $incidents = Incident::whereDate('created_at', $today)->count();

        // 4. Signups (Grouped)
        $signups = User::whereDate('created_at', $today)->get()->groupBy('role');
        $commuters = optional($signups->get(UserRole::USER))->count() ?? 0;
        $saccoMgrs = optional($signups->get(UserRole::SACCO))->count() ?? 0;
        $servicePersons = optional($signups->get(UserRole::SERVICE_PERSON))->count() ?? 0;
        $others = $signups->flatten()->count() - ($commuters + $saccoMgrs + $servicePersons);

        // 5. & 6. Signed In Today (Using ActivityLog joined with User)
        // ActivityLog models have `user_id`. We filter 'started_at' today.
        $activityLogs = ActivityLog::with('user')->whereDate('started_at', $today)->get();

        $managersLoggedIn = $activityLogs->filter(function ($log) {
            return $log->user && $log->user->role === UserRole::SACCO;
        })->unique('user_id')->count();

        $servicePersonsLoggedIn = $activityLogs->filter(function ($log) {
            return $log->user && $log->user->role === UserRole::SERVICE_PERSON;
        })->unique('user_id')->count();

        // 7. Sacco routes created today
        $routesCreated = 0;
        if (Schema::hasColumn('sacco_routes', 'created_at')) {
            $routesCreated = SaccoRoute::whereDate('created_at', $today)->count();
        }

        // 8. Super admin accesses
        // Assuming super admin paths start with 'superadmin' or contain 'admin'
        $superAdminActivity = [];
        foreach ($activityLogs as $log) {
            $urls = $log->urls_visited ?? [];
            foreach ($urls as $url) {
                // Handle both simple string path and complex array format
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
        $activeVehicles = VehicleLiveLocation::whereDate('recorded_at', $today)
            ->distinct('vehicle_id')
            ->count('vehicle_id');

        $newSaccos = Sacco::whereDate('join_date', $today)->count();

        // 10. Emails received today per nishukishe address
        $receivedEmailsCount = Email::where('type', 'incoming')
            ->whereDate('created_at', $today)
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

        $stats = [
            'searches' => $searches,
            'unique_visitors' => $uniqueVisitors,
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
        ];

        Mail::to('thigamatthew7@gmail.com')->send(new AdminDailySummaryEmail($stats));

        $this->info('Daily summary email sent successfully.');
        Log::info('Daily summary email sent successfully with stats:', $stats);
    }
}
