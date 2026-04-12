# Vehicle Tracking — Developer Guide

## What this module does
Tracks matatu positions in real-time. Drivers push GPS pings from their phones.
Commuters query the nearest active vehicle on their route.

## Files you own
- `VehicleTrackingService.php` — all tracking logic lives here
- `DriverController.php` / `DriverLocationController.php` — thin HTTP layer
- `VehicleLiveLocationController.php` — commuter-facing endpoints
- Models: `DriverLog`, `DriverLocation`, `VehicleLiveLocation`, `Vehicle`
- Policies: `VehiclePolicy`, `VehicleLiveLocationPolicy`
- Frontend: `app/driver/` + `app/service/map/`

## How it works (data flow)
1. Driver opens app → selects route → POST /api/driver/route (creates DriverLog)
2. Driver's phone pings every 30s → POST /api/tracking/ping → upserts vehicle_live_locations
3. Commuter opens map → GET /api/tracking/vehicles?sacco_route_id=X → returns active vehicles + ETA

## Tables you care about
| Table | Purpose |
|---|---|
| `vehicle_live_locations` | Current position, 1 row per vehicle (upserted) |
| `driver_locations` | Full ping history (**10-day retention**) |
| `driver_logs` | Shift records (driver + route + vehicle) |
| `vehicles` | Vehicle registry |

## Storage Management
To prevent storage bloat, `driver_locations` is cleared every 10 days. 
Historical data is for operational audit, not long-term storage.

## Do NOT touch
- `RoutePlannerController.php` — transit search, separate system
- `SaccoController.php` / `SaccoRoutesController.php` — route data ops
- `users` table directly — use Auth facade only
- Any table under `bookings/`, `parcels/`, `payments/`

## Running OSRM (for ETA)
ETA calculations call the local OSRM instance. Make sure it's running:
`docker ps | grep osrm`
