<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\StationRaptor;

class TestStationRaptor extends Command
{
    protected $signature = 'test:station-raptor';
    protected $description = 'Test Station-Based RAPTOR prototype';

    public function handle()
    {
        ini_set('memory_limit', '512M');
        $this->info("Starting StationRaptor Test...");
        $this->info("Initial Memory: " . $this->formatBytes(memory_get_usage()));

        try {
            $raptor = new StationRaptor();

            $this->info("1. Loading Data...");
            $startLoad = microtime(true);
            $raptor->loadData();
            $endLoad = microtime(true);
            $this->info("   Data Loaded in " . number_format($endLoad - $startLoad, 4) . "s");
            $this->info("   Memory after load: " . $this->formatBytes(memory_get_usage()));

            // 2. Define Origin/Dest
            $origin = '0210IKS'; // Westlands
            $dest = '0510KIT';   // Kitengela

            $this->info("\n2. Searching Route: Westlands ($origin) -> Kitengela ($dest)");

            $start = microtime(true);
            $result = $raptor->search($origin, $dest);
            $end = microtime(true);

            $this->info("   Search Time: " . number_format($end - $start, 4) . "s");
            $this->info("   Memory after search: " . $this->formatBytes(memory_get_usage()));

            if (isset($result['error'])) {
                $this->error("Error: " . $result['error']);
            } else {
                $this->info("\nFound " . count($result) . " potential routes.");

                foreach ($result as $i => $path) {
                    $this->info("\n--- Route Option " . ($i + 1) . " ---");
                    $this->printPath($path, $raptor, $origin, $dest);
                }
            }

        } catch (\Throwable $e) {
            $this->error("\nCRITICAL ERROR: " . $e->getMessage());
            $this->error("File: " . $e->getFile() . " Line: " . $e->getLine());
        }
    }

    private function printPath($path, $raptor, $origin, $dest)
    {
        $this->info("   Decomposing...");
        $start = microtime(true);
        $detailed = $raptor->expandPath($path, $origin, $dest);
        $end = microtime(true);
        $this->info("   Decomposition Time: " . number_format($end - $start, 4) . "s");

        foreach ($detailed as $leg) {
            $status = $leg['walk_valid'] ? "OK" : "BROKEN WALK";
            $color = $leg['walk_valid'] ? "info" : "error";

            $this->line("   Bus Route: {$leg['route_id']}");
            $this->line("   From: Station {$leg['from_station']} (Stop {$leg['from_stop']})");
            $this->line("   To:   Station {$leg['to_station']}   (Stop {$leg['to_stop']})");
            $this->{$color}("   Status: $status");
            $this->line("   ------------------------------------------------");
        }
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
