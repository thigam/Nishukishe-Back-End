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
            ->with(['driver', 'vehicle', 'saccoRoute.route'])
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

    /**
     * Get next active vehicle ETA (in minutes) on the route to a specific stop.
     */
    public function getRouteEta(string $saccoRouteId, string $stopId)
    {
        $saccoRoute = \App\Models\SaccoRoutes::where('sacco_route_id', $saccoRouteId)->first();
        if (!$saccoRoute) {
            return ['eta_minutes' => null, 'status' => 'inactive', 'message' => 'Route not found'];
        }

        // Get coordinates list
        $coords = $saccoRoute->coordinates; // Array of [lat, lng]
        if (empty($coords)) {
            return ['eta_minutes' => null, 'status' => 'inactive', 'message' => 'No geometry coordinates on route'];
        }

        // Find the target stop coordinates
        $targetStop = \App\Models\Stops::where('stop_id', $stopId)->first();
        if (!$targetStop) {
            return ['eta_minutes' => null, 'status' => 'inactive', 'message' => 'Target stop not found'];
        }

        // Fetch active, non-full tracking vehicles on this route
        $vehicles = VehicleLiveLocation::where('sacco_route_id', $saccoRouteId)
            ->where('is_active', true)
            ->where('is_full', false)
            ->get();

        if ($vehicles->isEmpty()) {
            return ['eta_minutes' => null, 'status' => 'scheduled', 'message' => 'No active vehicles on route'];
        }

        // Helper to find closest coordinate index in path
        $findClosestIndex = function (float $lat, float $lng) use ($coords) {
            $bestIdx = 0;
            $minDist = 999999;
            foreach ($coords as $idx => $pt) {
                if (!is_array($pt) || count($pt) < 2) continue;
                $dist = $this->haversine($lat, $lng, $pt[0], $pt[1]);
                if ($dist < $minDist) {
                    $minDist = $dist;
                    $bestIdx = $idx;
                }
            }
            return $bestIdx;
        };

        $targetIdx = $findClosestIndex($targetStop->latitude, $targetStop->longitude);
        $etas = [];

        foreach ($vehicles as $vehicle) {
            $vehIdx = $findClosestIndex($vehicle->lat, $vehicle->lng);

            // Vehicle must be before the stage along the path (direction validation)
            if ($vehIdx < $targetIdx) {
                // Calculate path distance by summing sequential haversine distances
                $distanceKm = 0;
                for ($i = $vehIdx; $i < $targetIdx; $i++) {
                    $distanceKm += $this->haversine(
                        $coords[$i][0], $coords[$i][1],
                        $coords[$i+1][0], $coords[$i+1][1]
                    );
                }

                // Speed calculation (fallback to 25 km/h if stationary or speed is low)
                $speedKmh = $vehicle->speed_kmh > 5 ? $vehicle->speed_kmh : 25;
                $etaMin = ($distanceKm / $speedKmh) * 60;

                // Cap ETA at 30 minutes
                if ($etaMin <= 30) {
                    $etas[] = (int) round($etaMin);
                }
            }
        }

        if (empty($etas)) {
            return ['etas' => [], 'eta_minutes' => null, 'status' => 'scheduled', 'message' => 'No incoming vehicles within 30 minutes'];
        }

        // Sort ascending and get top 3
        sort($etas);
        $topEtas = array_slice($etas, 0, 3);

        return [
            'etas' => $topEtas,
            'eta_minutes' => $topEtas[0],
            'status' => 'live',
        ];
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
