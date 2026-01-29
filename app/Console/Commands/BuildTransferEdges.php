<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\Directions;
use App\Models\TransferEdge;
use App\Models\Stops;

class BuildTransferEdges extends Command
{
protected $signature = 'transfers:build 
                        {--host=http://192.168.8.29:5001} 
                        {--cap=1200} 
                        {--bbox=} 
                        {--continue : Resume from previous run}
                        {--phases=all : Comma-separated phases to run (e.g. "2,3")}'; // <--- NEW FLAG    
protected $description = 'Build walking transfer edges using Hub-and-Spoke architecture';

    // Configuration
    private const RADIUS_LOCAL = 0.0045; // ~500m
    private const RADIUS_HUB_ACCESS = 0.0135; // ~1.5km
    private const RADIUS_BACKBONE = 0.0315; // ~3.5km

    private const LIMIT_LOCAL = 10;
    private const LIMIT_HUB_ACCESS = 3;
    private const LIMIT_BACKBONE = 15;
// Circuit Breaker: Store true (success) or false (fail) for the last 10 attempts
    private array $connectionHistory = [];
    private const HISTORY_LIMIT = 10;
    private const FAILURE_THRESHOLD = 3;

    // ..

    
public function handle()
{
    $host = rtrim($this->option('host'), '/');
    $cap = (int) $this->option('cap');
    $bbox = $this->option('bbox');
    $resume = $this->option('continue');
    
    // Parse Phases
    $phaseInput = $this->option('phases');
    if ($phaseInput === 'all') {
        $runPhases = [1, 2, 3, 4];
    } else {
        $runPhases = array_map('intval', explode(',', $phaseInput));
    }

    $this->info("Starting Build for Phases: " . implode(', ', $runPhases));

    // 0. Load Data
    $this->info("Loading stops and hubs...");
    $stops = $this->loadStops($bbox);
    $hubs = $this->loadHubs($stops);
    $hubIds = array_keys($hubs);
    $hubLookup = array_fill_keys($hubIds, true);
    $this->info("Loaded " . count($stops) . " stops and " . count($hubs) . " hubs.");

    // ---------------------------------------------------------
    // Phase 1: Local Neighbors (Iterates Stops)
    // ---------------------------------------------------------
    if (in_array(1, $runPhases)) {
        if (!$resume) {
            $this->info("Truncating table for fresh Phase 1 run...");
            DB::table('transfer_edges')->truncate();
        }
        
        $this->info("Phase 1: Building Local Neighbor Edges (500m)...");
        $total = count($stops);
        $current = 0;
        $localEdges = 0;
        
        foreach ($stops as $stop) {
            $current++;
            $candidates = $this->findNeighbors($stop, $stops, self::RADIUS_LOCAL, self::LIMIT_LOCAL);
            $localEdges += $this->processEdges($host, $stop, $candidates, $cap);
            
            // Log every 200 items
            if ($current % 200 === 0) {
                $pct = round(($current / $total) * 100);
                $this->info("[Phase 1] Progress: $current / $total ($pct%)");
            }
        }
        $this->info("Phase 1 Complete. Created $localEdges edges.");
    }

    // ---------------------------------------------------------
    // Phase 2: Hub Access (Iterates Stops)
    // ---------------------------------------------------------
    if (in_array(2, $runPhases)) {
        $this->info("Phase 2: Building Hub Access Edges (1.5km)...");
        $total = count($stops);
        $current = 0;
        $accessEdges = 0;

        foreach ($stops as $stop) {
            $current++;
            
            // Log every 200 items (Adjust this number if it's too spammy)
            if ($current % 200 === 0) {
                $pct = round(($current / $total) * 100);
                $this->info("[Phase 2] Progress: $current / $total ($pct%)");
            }

            if (isset($hubLookup[$stop->direction_id])) continue;

            $candidates = $this->findNeighbors($stop, $hubs, self::RADIUS_HUB_ACCESS, self::LIMIT_HUB_ACCESS);
            
            // Ingress (Stop -> Hub)
            $accessEdges += $this->processEdges($host, $stop, $candidates, $cap);
            
            // Egress (Hub -> Stop)
            foreach ($candidates as $hub) {
                $accessEdges += $this->processEdges($host, $hub, [$stop], $cap);
            }
        }
        $this->info("Phase 2 Complete. Created/Updated $accessEdges edges.");
    }

    // ---------------------------------------------------------
    // Phase 3: Hub Backbone (Iterates Hubs)
    // ---------------------------------------------------------
    if (in_array(3, $runPhases)) {
        $this->info("Phase 3: Building Hub Backbone (3.5km)...");
        $total = count($hubs);
        $current = 0;
        $backboneEdges = 0;

        foreach ($hubs as $hub) {
            $current++;
            
            // Log every 10 items (Since hubs are few, log more often)
            if ($current % 10 === 0) {
                $pct = round(($current / $total) * 100);
                $this->info("[Phase 3] Progress: $current / $total ($pct%)");
            }

            $candidates = $this->findNeighbors($hub, $hubs, self::RADIUS_BACKBONE, self::LIMIT_BACKBONE);
            $backboneEdges += $this->processEdges($host, $hub, $candidates, $cap);
        }
        $this->info("Phase 3 Complete. Created/Updated $backboneEdges edges.");
    }

    // ---------------------------------------------------------
    // Phase 4: Cluster Shortcuts
    // ---------------------------------------------------------
    if (in_array(4, $runPhases)) {
        $this->info("Phase 4: Building Cluster Shortcuts...");
        // Pass a simple callback to log progress from inside the function
        $shortcutEdges = $this->buildShortcuts($host, $cap, function($current, $total) {
            if ($current % 50 === 0) {
                $pct = round(($current / $total) * 100);
                $this->info("[Phase 4] Progress: $current / $total ($pct%)");
            }
        });
        $this->info("Phase 4 Complete. Created $shortcutEdges edges.");
    }

    $totalDB = DB::table('transfer_edges')->count();
    $this->info("Selected Phases Complete. Total Edges in DB: $totalDB");
}
private function loadStops(?string $bbox)
    {
        $query = Directions::query();
        if ($bbox) {
            $parts = array_map('floatval', explode(',', $bbox));
            if (count($parts) === 4) {
                [$minLat, $maxLat, $minLng, $maxLng] = $parts;
                $query->whereBetween('direction_latitude', [$minLat, $maxLat])
                    ->whereBetween('direction_longitude', [$minLng, $maxLng]);
            }
        }
        // Key by ID for easy lookup
        return $query->get()->keyBy('direction_id')->all();
    }

    private function loadHubs(array $validStops)
    {
        $hubStopIds = DB::table('transit_hubs')->pluck('stop_id')->toArray();
        $hubs = [];
        foreach ($hubStopIds as $id) {
            if (isset($validStops[$id])) {
                $hubs[$id] = $validStops[$id];
            }
        }
        return $hubs;
    }

    private function findNeighbors($origin, array $candidates, float $radius, int $limit)
    {
        $lat = (float) $origin->direction_latitude;
        $lng = (float) $origin->direction_longitude;
        $found = [];

        foreach ($candidates as $cand) {
            if ($cand->direction_id === $origin->direction_id)
                continue;

            $cLat = (float) $cand->direction_latitude;
            $cLng = (float) $cand->direction_longitude;

            // Quick box check
            if (abs($lat - $cLat) > $radius || abs($lng - $cLng) > $radius)
                continue;

            $dist = $this->haversineKm($lat, $lng, $cLat, $cLng);
            if ($dist <= ($radius * 111.0)) { // Approx conversion to km
                $found[] = ['stop' => $cand, 'dist' => $dist];
            }
        }

        usort($found, fn($a, $b) => $a['dist'] <=> $b['dist']);
        return array_map(fn($x) => $x['stop'], array_slice($found, 0, $limit));
    }

    private function processEdges($host, $origin, array $destinations, int $cap)
    {
        $count = 0;
        foreach ($destinations as $dest) {
            // Check if edge already exists (deduplication)
            if ($this->edgeExists($origin->direction_id, $dest->direction_id))
                continue;

            if ($this->createEdge($host, $origin, $dest, $cap)) {
                $count++;
            }
        }
        return $count;
    }

private function createEdge($host, $from, $to, $cap)
    {
        $url = "{$host}/route/v1/foot/{$from->direction_longitude},{$from->direction_latitude};{$to->direction_longitude},{$to->direction_latitude}?overview=full&geometries=geojson";

        try {
            // Set a hard limit of 5 seconds. If OSRM can't find a path in 5s, it's a bad path.
            $resp = Http::timeout(5)->get($url);

            if ($resp->ok()) {
                // SUCCESS
                $this->checkCircuitBreaker(true); // Reset breaker
                
                $duration = (int) round($resp->json('routes.0.duration', INF));
                if ($duration <= $cap) {
                    $rawGeom = $resp->json('routes.0.geometry.coordinates', []);
                    $geometry = array_map(fn($xy) => [$xy[1], $xy[0]], $rawGeom);

                    TransferEdge::updateOrCreate(
                        ['from_stop_id' => $from->direction_id, 'to_stop_id' => $to->direction_id],
                        ['walk_time_seconds' => $duration, 'geometry' => $geometry]
                    );
                    return true;
                }
                return false;
            } elseif ($resp->status() === 400) {
                // No Route Found - just skip
                return false;
            } else {
                $this->warn("Server Error: " . $resp->status());
                return false;
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // === THIS IS THE FIX ===
            // This catches Timeouts (Curl error 28).
            // We treat this as a "Poison Pill" (Bad Data), NOT a network crash.
            $this->warn("⏳ POISON PILL (Timeout) skipped: {$from->direction_id} -> {$to->direction_id}");
            
            // Do NOT trip the circuit breaker.
            // Do NOT crash.
            // Just return false and move to the next one.
            return false; 

        } catch (\Exception $e) {
            // Other errors (like "Connection Refused") are still real crashes
            $this->warn("Real Network Drop: " . $e->getMessage());
            $this->checkCircuitBreaker(false);
            return false;
        }
}

// Add the $progressCallback = null argument
private function buildShortcuts($host, $cap, $progressCallback = null)
{
    // 1. Get all Hub-to-Hub edges (Backbone)
    $hubEdges = TransferEdge::whereIn('from_stop_id', function ($q) {
        $q->select('stop_id')->from('transit_hubs');
    })->whereIn('to_stop_id', function ($q) {
        $q->select('stop_id')->from('transit_hubs');
    })->get();

    $count = 0;
    $total = $hubEdges->count(); // Get total for progress
    $current = 0;

    $stops = $this->loadStops(null); 

    foreach ($hubEdges as $edge) {
        $current++;
        
        // Trigger the callback if provided
        if ($progressCallback) {
            $progressCallback($current, $total);
        }

        $h1 = $edge->from_stop_id;
        $h2 = $edge->to_stop_id;

        // ... Rest of your existing logic ...
        $spokes1 = TransferEdge::where('to_stop_id', $h1)->pluck('from_stop_id')->toArray();
        $spokes2 = TransferEdge::where('from_stop_id', $h2)->pluck('to_stop_id')->toArray();

        $spokes1[] = $h1;
        $spokes2[] = $h2;
        $spokes1 = array_unique($spokes1);
        $spokes2 = array_unique($spokes2);

        foreach ($spokes1 as $s1) {
            foreach ($spokes2 as $s2) {
                if ($s1 === $s2) continue;
                if (!isset($stops[$s1]) || !isset($stops[$s2])) continue;
                if ($this->edgeExists($s1, $s2)) continue;

                if ($this->createEdge($host, $stops[$s1], $stops[$s2], $cap)) {
                    $count++;
                }
            }
        }
    }
    return $count;
}
    private function edgeExists($from, $to)
    {
        // Simple memory cache could optimize this if needed
        return DB::table('transfer_edges')
            ->where('from_stop_id', $from)
            ->where('to_stop_id', $to)
            ->exists();
    }

    private function haversineKm($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
private function checkCircuitBreaker(bool $wasSuccessful)
    {
        // Add result to history
        $this->connectionHistory[] = $wasSuccessful;

        // Keep only the last 10 results
        if (count($this->connectionHistory) > self::HISTORY_LIMIT) {
            array_shift($this->connectionHistory);
        }

        // Count failures (false values)
        $failures = count(array_filter($this->connectionHistory, fn($r) => $r === false));

        // CRASH if we hit the threshold (3 failures in the last 10 attempts)
        if ($failures >= self::FAILURE_THRESHOLD) {
            $this->error("💥 CIRCUIT BREAKER TRIPPED! Too many network errors ($failures in last " . self::HISTORY_LIMIT . "). Crashing to trigger restart...");
            exit(1); // Non-zero exit code triggers your shell script to restart
        }
    }
}
