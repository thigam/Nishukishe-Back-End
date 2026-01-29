<?php

namespace App\Services;

use App\Services\H3Wrapper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
/**
 * Builds a corridor whitelist using precomputed L0/L1 graphs:
 *   L0: A* over regional cells -> L0 corridor (+1 ring buffer)
 *   L1: A* inside L0 corridor band -> L1 corridor (+1 ring buffer)
 *   L2: Station whitelist = stations whose L1 cell is in L1 corridor (+ buffer)
 *
 * Provides a widen-once fallback that expands L1 band by one more ring.
 */

class CorridorPlanner
{
    // ... existing code ...

    /**
     * Public entry: build whitelist using a cell-graph shortest path.
     * Signature kept compatible with your existing buildWhitelist().
     */
    public function buildWhitelist(
        float $olat,
        float $olng,
        float $dlat,
        float $dlng,
        bool $widen = false
    ): array {
        // 1) choose level: L1 for “normal”, L0 for very long trips
        $odKm = $this->haversineKm($olat, $olng, $dlat, $dlng);
        $level = $odKm >= 120.0 ? 0 : 1; // tweak threshold as you like

        // 2) find nearest cells (origin & dest) on this level
        $fromCell = $this->closestCellForPoint($olat, $olng, $level);
        $toCell   = $this->closestCellForPoint($dlat, $dlng, $level);

        if (!$fromCell || !$toCell) {
            // fallback: old behaviour or empty plan
            return [
                'L1' => [],
                'L0' => [],
                'allowedStops' => [],
                'allowedCellsRes9' => [],
            ];
        }

        // 3) run Dijkstra over the cell graph
        $cellPath = $this->shortestCellPath($fromCell, $toCell, $level, $widen);

        if (empty($cellPath)) {
            return [
                'L1' => [],
                'L0' => [],
                'allowedStops' => [],
                'allowedCellsRes9' => [],
            ];
        }

        // 4) widen corridor by neighbors if requested
        if ($widen) {
            $cellPath = $this->widenCells($cellPath, $level);
        }

        $cellPath = array_values(array_unique($cellPath));

        // 5) derive L1 / L0 lists
        $L1 = [];
        $L0 = [];
        if ($level === 1) {
            $L1 = $cellPath;
            $L0 = $this->parentL0CellsForL1($L1);
        } else {
            $L0 = $cellPath;
            // optional: infer L1 as children; not strictly needed
            $L1 = $this->childL1CellsForL0($L0);
        }

        // 6) build initial allowedStops from portals on the path
        $allowedStops = $this->allowedStopsFromCellPath($cellPath, $level);

        return [
            'L1' => $L1,
            'L0' => $L0,
            'allowedStops' => $allowedStops,
            // we don’t rely on this anymore; applyCorridorWhitelist
            // will expand via allowedStops & portals
            'allowedCellsRes9' => [],
        ];
    }

    /**
     * Find nearest corridor cell (L1 or L0) to a point.
     */
    protected function closestCellForPoint(
        float $lat,
        float $lng,
        int $level
    ): ?string {
        $row = DB::table('corr_cells')
            ->where('level', $level)
            ->selectRaw("
                cell_id,
                6371000 * acos(
                    cos(radians(?)) * cos(radians(lat)) *
                    cos(radians(lng) - radians(?)) +
                    sin(radians(?)) * sin(radians(lat))
                ) AS dist
            ", [$lat, $lng, $lat])
            ->orderBy('dist')
            ->first();

        return $row ? (string) $row->cell_id : null;
    }

    /**
     * Dijkstra over cell graph using edge weights from corr_cell_edge_summaries.
     * If an edge has no summaries, we fall back to crow-flies.
     */
    protected function shortestCellPath(
        string $fromCell,
        string $toCell,
        int $level,
        bool $widen
    ): array {
        // Build adjacency list: cell => [neighborCell => minutes]
        $neighbors = DB::table('corr_cell_neighbors')
            ->where('level', $level)
            ->get();

        $adj = [];

        foreach ($neighbors as $edge) {
            $a = (string) $edge->cell_a;
            $b = (string) $edge->cell_b;

            $wAB = $this->cellEdgeMinutes($a, $b, $level);
            $wBA = $this->cellEdgeMinutes($b, $a, $level);

            if (!isset($adj[$a])) $adj[$a] = [];
            if (!isset($adj[$b])) $adj[$b] = [];

            // store best (min) weight if multiple rows exist
            $adj[$a][$b] = isset($adj[$a][$b]) ? min($adj[$a][$b], $wAB) : $wAB;
            $adj[$b][$a] = isset($adj[$b][$a]) ? min($adj[$b][$a], $wBA) : $wBA;
        }

        if (!isset($adj[$fromCell]) || !isset($adj[$toCell])) {
            return [];
        }

        // Dijkstra
        $dist = [];
        $prev = [];
        $queue = [];

        foreach ($adj as $cell => $_) {
            $dist[$cell] = INF;
            $prev[$cell] = null;
            $queue[$cell] = true;
        }
        $dist[$fromCell] = 0.0;

        while (!empty($queue)) {
            // pick cell in queue with smallest distance
            $u = null;
            $best = INF;
            foreach ($queue as $cell => $_) {
                if ($dist[$cell] < $best) {
                    $best = $dist[$cell];
                    $u = $cell;
                }
            }
            if ($u === null) break;
            unset($queue[$u]);

            if ($u === $toCell) break; // reached target

            foreach ($adj[$u] as $v => $w) {
                if (!isset($queue[$v])) continue;
                $alt = $dist[$u] + $w;
                if ($alt < $dist[$v]) {
                    $dist[$v] = $alt;
                    $prev[$v] = $u;
                }
            }
        }

        if (!is_finite($dist[$toCell])) {
            return [];
        }

        // Reconstruct path
        $path = [];
        for ($u = $toCell; $u !== null; $u = $prev[$u]) {
            $path[] = $u;
        }
        return array_reverse($path);
    }

    /**
     * Weight for edge between two cells, using corr_cell_edge_summaries minutes.
     * Falls back to crow-flies between cell centroids if no summary exists.
     */
    protected function cellEdgeMinutes(string $fromCell, string $toCell, int $level): float
    {
        $row = DB::table('corr_cell_edge_summaries')
            ->where('level', $level)
            ->where('from_cell', $fromCell)
            ->where('to_cell', $toCell)
            ->orderBy('minutes')
            ->first();

        if ($row) {
            return (float) $row->minutes;
        }

        // fallback: crow-flies between cell centroids
        $a = DB::table('corr_cells')->where('cell_id', $fromCell)->where('level',$level)->first();
        $b = DB::table('corr_cells')->where('cell_id', $toCell)->where('level',$level)->first();

        if (!$a || !$b) {
            return 9999.0; // effectively discourage this edge
        }

        $km = $this->haversineKm((float)$a->lat, (float)$a->lng, (float)$b->lat, (float)$b->lng);
        $speed = 22.0; // km/h, same as BUS in BuildCorridorData
        return ($km / $speed) * 60.0;
    }

    /**
     * Simple widening: add neighbors of cells along the path.
     */
    protected function widenCells(array $cells, int $level): array
    {
        $set = array_fill_keys($cells, true);

        $rows = DB::table('corr_cell_neighbors')
            ->where('level', $level)
            ->whereIn('cell_a', $cells)
            ->get();

        foreach ($rows as $r) {
            $set[(string)$r->cell_b] = true;
        }

        return array_keys($set);
    }

    protected function parentL0CellsForL1(array $l1Cells): array
    {
        if (empty($l1Cells)) return [];
        $rows = DB::table('corr_cells')
            ->where('level', 1)
            ->whereIn('cell_id', $l1Cells)
            ->whereNotNull('l0_parent')
            ->pluck('l0_parent')
            ->all();

        return array_values(array_unique(array_map('strval', $rows)));
    }

    protected function childL1CellsForL0(array $l0Cells): array
    {
        if (empty($l0Cells)) return [];
        $rows = DB::table('corr_cells')
            ->where('level', 1)
            ->whereIn('l0_parent', $l0Cells)
            ->pluck('cell_id')
            ->all();

        return array_values(array_unique(array_map('strval', $rows)));
    }

    /**
     * From a list of cells on a given level, collect portal stations + members → stop_ids.
     */
    protected function allowedStopsFromCellPath(array $cells, int $level): array
    {
        if (empty($cells)) return [];

        $stationIds = DB::table('corr_cell_portals')
            ->where('level', $level)
            ->whereIn('cell_id', $cells)
            ->orderBy('rank')
            ->pluck('station_id')
            ->all();

        if (empty($stationIds)) return [];

        $stopIds = DB::table('corr_station_members')
            ->whereIn('station_id', $stationIds)
            ->pluck('stop_id')
            ->all();

        $stopIds = array_values(array_unique(array_map('strval', $stopIds)));
        return $stopIds;
    }

    /**
     * Simple haversine for doubles (copy/paste from your other class if needed).
     */
    protected function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)**2;
        return 2*$R*asin(min(1.0, sqrt($a)));
    }
}

