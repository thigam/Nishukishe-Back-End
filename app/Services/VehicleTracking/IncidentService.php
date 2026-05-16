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
                'reported_at'       => now(),
            ]);

            $this->checkConsensus($incident);

            return $incident;
        });
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
