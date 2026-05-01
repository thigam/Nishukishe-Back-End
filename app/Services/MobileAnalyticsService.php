<?php

namespace App\Services;

use App\Models\DeviceSession;
use App\Models\LocationPing;
use App\Models\DeviceToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MobileAnalyticsService
{
    public function getSummary(?string $start = null, ?string $end = null): array
    {
        $startDate = $start ? Carbon::parse($start) : Carbon::now()->subDays(30);
        $endDate = $end ? Carbon::parse($end) : Carbon::now();

        $sessionQuery = DeviceSession::whereBetween('created_at', [$startDate, $endDate]);
        
        $totalSessions = (clone $sessionQuery)->count();
        $uniqueDevices = (clone $sessionQuery)->distinct('device_id')->count('device_id');
        $avgDuration = (clone $sessionQuery)->whereNotNull('duration_seconds')->avg('duration_seconds') ?? 0;
        
        $totalPings = LocationPing::whereBetween('created_at', [$startDate, $endDate])->count();
        $activeTokens = DeviceToken::where('is_active', true)->count();

        return [
            'total_sessions' => $totalSessions,
            'unique_devices' => $uniqueDevices,
            'avg_session_duration_seconds' => round($avgDuration, 2),
            'total_location_pings' => $totalPings,
            'active_push_tokens' => $activeTokens,
        ];
    }

    public function getSessionSeries(?string $start = null, ?string $end = null): array
    {
        $startDate = $start ? Carbon::parse($start) : Carbon::now()->subDays(30);
        $endDate = $end ? Carbon::parse($end) : Carbon::now();

        $data = DeviceSession::whereBetween('created_at', [$startDate, $endDate])
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $data->toArray();
    }

    public function getPlatformBreakdown(): array
    {
        return DeviceSession::select('platform', DB::raw('count(distinct device_id) as device_count'))
            ->groupBy('platform')
            ->get()
            ->toArray();
    }

    public function getLocationHeatmap(): array
    {
        // Simple bucketing for heatmap (round to 3 decimal places ~110m precision)
        $data = LocationPing::select(
            DB::raw('ROUND(lat, 3) as lat'),
            DB::raw('ROUND(lng, 3) as lng'),
            DB::raw('count(*) as weight')
        )
        ->groupBy('lat', 'lng')
        ->orderByDesc('weight')
        ->limit(500)
        ->get();

        return $data->toArray();
    }
}
