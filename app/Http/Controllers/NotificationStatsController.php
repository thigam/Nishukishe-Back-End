<?php

namespace App\Http\Controllers;

use App\Models\IncidentNotification;
use App\Models\NotificationCampaign;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NotificationStatsController extends Controller
{
    /**
     * Get statistics for automated incident notifications.
     *
     * Query params:
     *   bin   = 'day' (default) | 'month'
     *   start = date string (defaults to 30 days ago)
     *   end   = date string (defaults to today)
     */
    public function automatedStats(Request $request)
    {
        $bin   = $request->query('bin', 'day');
        $start = $request->query('start') ? Carbon::parse($request->query('start'))->startOfDay() : Carbon::now()->subDays(30)->startOfDay();
        $end   = $request->query('end')   ? Carbon::parse($request->query('end'))->endOfDay()   : Carbon::now()->endOfDay();

        // ── Overall totals ────────────────────────────────────────────────────
        $baseQuery   = IncidentNotification::whereBetween('incident_notifications.created_at', [$start, $end]);

        $totalSent   = (clone $baseQuery)->count();
        $totalClicks = (clone $baseQuery)->whereNotNull('clicked_at')->count();
        $ctr         = $totalSent > 0 ? round(($totalClicks / $totalSent) * 100, 1) : 0;

        // ── Mobile vs Web split ──────────────────────────────────────────────
        // Differentiate by device_id prefix: web device IDs are prefixed with 'web-'
        $mobileSent = (clone $baseQuery)
            ->where(function ($q) {
                $q->whereNull('incident_notifications.device_id')
                  ->orWhere('incident_notifications.device_id', 'not like', 'web-%');
            })
            ->count();

        $webSent = (clone $baseQuery)
            ->where('incident_notifications.device_id', 'like', 'web-%')
            ->count();

        // ── Time-series (binned by day or month) ─────────────────────────────
        $dateFormat = $bin === 'month' ? '%Y-%m' : '%Y-%m-%d';

        $series = IncidentNotification::whereBetween('incident_notifications.created_at', [$start, $end])
            ->select(
                DB::raw("DATE_FORMAT(incident_notifications.created_at, '{$dateFormat}') as period"),
                DB::raw('COUNT(*) as sent'),
                DB::raw('SUM(CASE WHEN incident_notifications.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicks')
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        // ── Breakdown by incident type ────────────────────────────────────────
        $byType = IncidentNotification::whereBetween('incident_notifications.created_at', [$start, $end])
            ->join('incidents', 'incident_notifications.incident_id', '=', 'incidents.id')
            ->select('incidents.type', DB::raw('count(*) as count'))
            ->groupBy('incidents.type')
            ->get();

        return response()->json([
            'total_sent'   => $totalSent,
            'total_clicks' => $totalClicks,
            'ctr'          => $ctr,
            'mobile_sent'  => $mobileSent,
            'web_sent'     => $webSent,
            'series'       => $series,
            'by_type'      => $byType,
            'bin'          => $bin,
            'start'        => $start->toDateString(),
            'end'          => $end->toDateString(),
        ]);
    }

    /**
     * Record a click for an automated notification.
     */
    public function trackAutomatedClick(Request $request, $id)
    {
        $notification = IncidentNotification::find($id);
        if ($notification && !$notification->clicked_at) {
            $notification->clicked_at = now();
            $notification->save();
        }
        return response()->json(['success' => true]);
    }

    /**
     * Record a click for an onboarding notification.
     */
    public function trackOnboardingClick(Request $request, $id)
    {
        $notification = \App\Models\OnboardingNotification::find($id);
        if ($notification && !$notification->clicked_at) {
            $notification->clicked_at = now();
            $notification->save();
        }
        return response()->json(['success' => true]);
    }

    /**
     * Get statistics for onboarding notifications.
     */
    public function onboardingStats(Request $request)
    {
        $bin   = $request->query('bin', 'day');
        $start = $request->query('start') ? Carbon::parse($request->query('start'))->startOfDay() : Carbon::now()->subDays(30)->startOfDay();
        $end   = $request->query('end')   ? Carbon::parse($request->query('end'))->endOfDay()   : Carbon::now()->endOfDay();

        $baseQuery = \App\Models\OnboardingNotification::whereBetween('created_at', [$start, $end]);

        $totalSent   = (clone $baseQuery)->count();
        $totalClicks = (clone $baseQuery)->whereNotNull('clicked_at')->count();
        $ctr         = $totalSent > 0 ? round(($totalClicks / $totalSent) * 100, 1) : 0;

        // Breakdown by tip_key
        $byTip = \App\Models\OnboardingNotification::whereBetween('created_at', [$start, $end])
            ->select(
                'tip_key',
                DB::raw('COUNT(*) as sent'),
                DB::raw('SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicks')
            )
            ->groupBy('tip_key')
            ->get()
            ->map(function ($item) {
                $item->ctr = $item->sent > 0 ? round(($item->clicks / $item->sent) * 100, 1) : 0;
                return $item;
            });

        // Time-series (binned by day or month)
        $dateFormat = $bin === 'month' ? '%Y-%m' : '%Y-%m-%d';

        $series = \App\Models\OnboardingNotification::whereBetween('created_at', [$start, $end])
            ->select(
                DB::raw("DATE_FORMAT(created_at, '{$dateFormat}') as period"),
                DB::raw('COUNT(*) as sent'),
                DB::raw('SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicks')
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return response()->json([
            'total_sent'   => $totalSent,
            'total_clicks' => $totalClicks,
            'ctr'          => $ctr,
            'by_tip'       => $byTip,
            'series'       => $series,
            'bin'          => $bin,
            'start'        => $start->toDateString(),
            'end'          => $end->toDateString(),
        ]);
    }
}
