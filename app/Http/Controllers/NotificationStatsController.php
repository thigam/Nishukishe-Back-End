<?php

namespace App\Http\Controllers;

use App\Models\IncidentNotification;
use App\Models\NotificationCampaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationStatsController extends Controller
{
    /**
     * Get statistics for automated incident notifications.
     */
    public function automatedStats(Request $request)
    {
        $totalSent = IncidentNotification::count();
        $totalClicks = IncidentNotification::whereNotNull('clicked_at')->count();
        $ctr = $totalSent > 0 ? round(($totalClicks / $totalSent) * 100, 1) : 0;

        // Group by incident type
        $byType = IncidentNotification::join('incidents', 'incident_notifications.incident_id', '=', 'incidents.id')
            ->select('incidents.type', DB::raw('count(*) as count'))
            ->groupBy('incidents.type')
            ->get();

        return response()->json([
            'total_sent' => $totalSent,
            'total_clicks' => $totalClicks,
            'ctr' => $ctr,
            'by_type' => $byType
        ]);
    }

    /**
     * Record a click for an automated notification
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
}
