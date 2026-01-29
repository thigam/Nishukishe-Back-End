<?php

namespace App\Services;

use App\Models\Parcel;
use App\Models\SearchMetric;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Generate summary metrics for parcels.
     *
     * @param string|null $routeId Filter by route (uses sacco_id).
     * @param string|null $start   Start date (YYYY-mm-dd).
     * @param string|null $end     End date (YYYY-mm-dd).
     * @return array
     */
    public function summary(?string $routeId = null, ?string $start = null, ?string $end = null): array
    {
        $query = Parcel::query();

        if ($routeId) {
            // In this prototype parcels are linked to saccos; use sacco_id as route filter.
            $query->where('sacco_id', $routeId);
        }
        if ($start) {
            $query->whereDate('created_at', '>=', $start);
        }
        if ($end) {
            $query->whereDate('created_at', '<=', $end);
        }

        $statusCounts = (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $routeCounts = (clone $query)
            ->select('sacco_id as route', DB::raw('COUNT(*) as count'))
            ->groupBy('sacco_id')
            ->pluck('count', 'route');

        $revenue = (clone $query)->count() * 100; // Assume default fare of 100 per parcel

        return [
            'status_counts' => $statusCounts,
            'route_counts'  => $routeCounts,
            'revenue'       => $revenue,
        ];
    }

    /**
     * Fetch recent parcel deliveries.
     *
     * @param string|null $routeId
     * @param string|null $start
     * @param string|null $end
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function recentDeliveries(?string $routeId = null, ?string $start = null, ?string $end = null, int $limit = 5)
    {
        $query = Parcel::query()->orderByDesc('created_at');

        if ($routeId) {
            $query->where('sacco_id', $routeId);
        }
        if ($start) {
            $query->whereDate('created_at', '>=', $start);
        }
        if ($end) {
            $query->whereDate('created_at', '<=', $end);
        }

        return $query->take($limit)->get(['package_id', 'status', 'created_at']);
    }

    /**
     * Aggregate search appearances and ranking statistics.
     *
     * @param string|null $saccoId Filter by sacco.
     * @param string|null $start   Start date (YYYY-mm-dd).
     * @param string|null $end     End date (YYYY-mm-dd).
     * @return \Illuminate\Support\Collection
     */
    public function searchMetrics(?string $saccoId = null, ?string $start = null, ?string $end = null)
    {
        $query = SearchMetric::query();

        if ($saccoId) {
            $query->where('sacco_id', $saccoId);
        }
        if ($start) {
            $query->whereDate('created_at', '>=', $start);
        }
        if ($end) {
            $query->whereDate('created_at', '<=', $end);
        }
return $query
    ->select(
        'sacco_id',
        DB::raw('COUNT(*) as appearances'),
        DB::raw('AVG(`rank`) as avg_rank')   // <-- change made here
    )
    ->groupBy('sacco_id')
    ->orderByDesc('appearances')
    ->get();

    }
}

