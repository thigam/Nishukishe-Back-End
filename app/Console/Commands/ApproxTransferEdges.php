<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Directions;

class ApproxTransferEdges extends Command
{
    protected $signature = 'transfers:approx {--speed=1.4} {--cap=600}';
    protected $description = 'Approximate walking transfer edges using Haversine distance';

    public function handle()
    {
        $speed = (float) $this->option('speed'); // meters per second
        $cap   = (int) $this->option('cap');

        DB::table('transfer_edges')->truncate();

        foreach (Directions::all() as $dir) {
            $lat = (float) $dir->direction_latitude;
            $lng = (float) $dir->direction_longitude;

            $candidates = Directions::where('direction_latitude', '>=', $lat - 0.005)
                ->where('direction_latitude', '<=', $lat + 0.005)
                ->where('direction_longitude', '>=', $lng - 0.005)
                ->where('direction_longitude', '<=', $lng + 0.005)
                ->where('direction_id', '!=', $dir->direction_id)
                ->get();

            foreach ($candidates as $cand) {
                $distance = $this->haversine(
                    $lat,
                    $lng,
                    (float) $cand->direction_latitude,
                    (float) $cand->direction_longitude
                );
                $duration = (int) round($distance / $speed);

                if ($duration <= $cap) {
                    DB::table('transfer_edges')->insertOrIgnore([
                        'from_stop_id'      => $dir->direction_id,
                        'to_stop_id'        => $cand->direction_id,
                        'walk_time_seconds' => $duration,
                    ]);
                }
            }
        }

        $rows = DB::table('transfer_edges')->count();
        $this->info("transfer_edges populated: {$rows} rows");
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000; // radius in meters
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earth * $c;
    }
}
