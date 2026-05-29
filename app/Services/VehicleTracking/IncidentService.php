<?php

namespace App\Services\VehicleTracking;

use App\Models\Incident;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IncidentService
{
    /**
     * Report an incident.
     */
    public function report(?int $driverId, ?int $vehicleId, ?int $userId, array $data)
    {
        return DB::transaction(function () use ($driverId, $vehicleId, $userId, $data) {
            $roads = $this->resolveRoadsWithinRadius($data['lat'], $data['lng'], 300);

            $incident = Incident::create([
                'driver_id'         => $driverId,
                'vehicle_id'        => $vehicleId,
                'user_id'           => $userId,
                'type'              => $data['type'],
                'lat'               => $data['lat'],
                'lng'               => $data['lng'],
                'description'       => $data['description'] ?? null,
                'incident_sub_type' => $data['incident_sub_type'] ?? null,
                'path_coordinates'  => $data['path_coordinates'] ?? null,
                'start_time'        => $data['start_time'] ?? null,
                'end_time'          => $data['end_time'] ?? null,
                'roads'             => empty($roads) ? null : $roads,
                'reported_at'       => now(),
            ]);

            $this->checkConsensus($incident);

            return $incident;
        });
    }

    /**
     * Resolve all unique road names within a given radius in meters.
     */
    public function resolveRoadsWithinRadius(float $lat, float $lng, float $radiusMeters = 300): array
    {
        $delta = $radiusMeters / 111320;

        $candidates = DB::table('roads')
            ->where('lat_min', '<=', $lat + $delta)
            ->where('lat_max', '>=', $lat - $delta)
            ->where('lng_min', '<=', $lng + $delta)
            ->where('lng_max', '>=', $lng - $delta)
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $matchedRoadNames = [];

        foreach ($candidates as $road) {
            $coords = json_decode($road->geometry, true);
            if (!is_array($coords) || count($coords) < 2) {
                continue;
            }

            $dist = $this->distanceToPolyline($lat, $lng, $coords);
            if ($dist <= $radiusMeters) {
                $matchedRoadNames[] = $road->name;
            }
        }

        return array_values(array_unique($matchedRoadNames));
    }

    private function distanceToPolyline(float $lat, float $lng, array $coords): float
    {
        $minDist = INF;
        for ($i = 0; $i < count($coords) - 1; $i++) {
            $p1 = $coords[$i];
            $p2 = $coords[$i + 1];

            $lat1 = $p1['lat'];
            $lng1 = $p1['lng'];
            $lat2 = $p2['lat'];
            $lng2 = $p2['lng'];

            $dist = $this->distanceToSegment($lat, $lng, $lat1, $lng1, $lat2, $lng2);
            if ($dist < $minDist) {
                $minDist = $dist;
            }
        }
        return $minDist;
    }

    private function distanceToSegment(float $lat, float $lng, float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $cosLat = cos(deg2rad(($lat1 + $lat2) / 2));

        $py = ($lat - $lat1) * 111320;
        $px = ($lng - $lng1) * 111320 * $cosLat;

        $ay = ($lat2 - $lat1) * 111320;
        $ax = ($lng2 - $lng1) * 111320 * $cosLat;

        $ab_len_sq = $ax * $ax + $ay * $ay;
        if ($ab_len_sq == 0) {
            return sqrt($px * $px + $py * $py);
        }

        $t = ($px * $ax + $py * $ay) / $ab_len_sq;
        $t = max(0.0, min(1.0, $t));

        $cx = $t * $ax;
        $cy = $t * $ay;

        $dx = $px - $cx;
        $dy = $py - $cy;

        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * Check if an incident should be verified based on consensus.
     * Rule: ≥ 5 reports of the same type within 500m in the last 2 hours.
     */
    protected function checkConsensus(Incident $incident)
    {
        $twoHoursAgo = now()->subHours(2);
        $radiusKm = 0.5; // 500m

        // Box approximation for performance
        $latDelta = $radiusKm / 111.32;
        $lngDelta = $radiusKm / (111.32 * cos(deg2rad($incident->lat)));

        $similarReportsCount = Incident::where('type', $incident->type)
            ->where('reported_at', '>=', $twoHoursAgo)
            ->whereBetween('lat', [$incident->lat - $latDelta, $incident->lat + $latDelta])
            ->whereBetween('lng', [$incident->lng - $lngDelta, $incident->lng + $lngDelta])
            ->count();

        if ($similarReportsCount >= 5) {
            // Mark all related reports as verified in this area
            Incident::where('type', $incident->type)
                ->where('reported_at', '>=', $twoHoursAgo)
                ->whereBetween('lat', [$incident->lat - $latDelta, $incident->lat + $latDelta])
                ->whereBetween('lng', [$incident->lng - $lngDelta, $incident->lng + $lngDelta])
                ->update(['is_verified' => true]);
        }
    }

    /**
     * Get verified incidents for display.
     */
    public function getVerifiedIncidents()
    {
        return Incident::where('is_verified', true)
            ->where('reported_at', '>=', now()->subHours(2))
            ->orderByDesc('reported_at')
            ->get();
    }

    public function upvote(Incident $incident, int $userId)
    {
        if (\App\Models\IncidentVote::where('user_id', $userId)->where('incident_id', $incident->id)->exists()) {
            throw new \Exception("You have already voted on this incident.");
        }
        \App\Models\IncidentVote::create(['user_id' => $userId, 'incident_id' => $incident->id, 'type' => 'up']);
        $incident->increment('upvotes');
    }

    public function downvote(Incident $incident, int $userId)
    {
        if (\App\Models\IncidentVote::where('user_id', $userId)->where('incident_id', $incident->id)->exists()) {
            throw new \Exception("You have already voted on this incident.");
        }
        \App\Models\IncidentVote::create(['user_id' => $userId, 'incident_id' => $incident->id, 'type' => 'down']);

        $incident->increment('downvotes');

        if ($incident->downvotes >= 3 && $incident->downvotes > $incident->upvotes) {
            $incident->update(['is_verified' => false]);
        }
    }
}
