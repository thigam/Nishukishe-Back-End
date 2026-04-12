<?php

namespace App\Services\VehicleTracking;

use App\Models\VehicleLiveLocation;
use App\Models\DriverLocation;
use App\Models\DriverLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class VehicleTrackingService
{
    /**
     * Track a vehicle ping.
     * Upserts the live location and logs the historical data.
     */
    public function trackPing(int $driverId, array $data)
    {
        return DB::transaction(function () use ($driverId, $data) {
            $vehicleId = $data['vehicle_id'];
            $recordedAt = $data['recorded_at'] ?? now();

            // 1. Log historical data
            DriverLocation::create([
                'driver_id' => $driverId,
                'vehicle_id' => $vehicleId,
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'recorded_at' => $recordedAt,
            ]);

            // 2. Find active shift
            $activeLog = DriverLog::where('driver_id', $driverId)
                ->where('vehicle_id', $vehicleId)
                ->active()
                ->first();

            // 3. Upsert live location
            return VehicleLiveLocation::updateOrCreate(
                ['vehicle_id' => $vehicleId],
                [
                    'driver_id' => $driverId,
                    'sacco_route_id' => $activeLog?->sacco_route_id,
                    'lat' => $data['lat'],
                    'lng' => $data['lng'],
                    'heading' => $data['heading'] ?? 0,
                    'speed_kmh' => $data['speed_kmh'] ?? 0,
                    'location_source' => $data['location_source'] ?? 'driver_app',
                    'is_active' => true,
                    'is_full' => $data['is_full'] ?? false,
                    'recorded_at' => $recordedAt,
                ]
            );
        });
    }

    /**
     * Get nearby vehicles with ETA.
     */
    public function getNearbyVehicles(string $saccoRouteId, float $lat, float $lng)
    {
        // Commuters can see all active vehicles on the route
        return VehicleLiveLocation::where('sacco_route_id', $saccoRouteId)
            ->active()
            ->get();
    }

    /**
     * Get all active vehicles for a Sacco, respecting owner privacy.
     */
    public function getFleetForSacco(string $saccoId)
    {
        return VehicleLiveLocation::select('vehicle_live_locations.*')
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_live_locations.vehicle_id')
            ->where('vehicles.sacco_id', $saccoId)
            ->where('vehicles.share_location_with_sacco', true)
            ->active()
            ->get();
    }

    /**
     * Get all vehicles for an owner (full visibility).
     */
    public function getFleetForOwner(int $ownerId)
    {
        return VehicleLiveLocation::select('vehicle_live_locations.*')
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_live_locations.vehicle_id')
            ->where('vehicles.owner_id', $ownerId)
            ->get();
    }
}
