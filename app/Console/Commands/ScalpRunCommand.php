<?php

namespace App\Console\Commands;

use App\Services\Scalping\ScalpingService;
use Illuminate\Console\Command;

class ScalpRunCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scalp:run {origin} {destination} {date} {--provider=all} {--create : Create safaris for high-confidence matches}';

    // ...

    public function handle(ScalpingService $scalper)
    {
        $origin = $this->argument('origin');
        $destination = $this->argument('destination');
        $date = $this->argument('date');
        $shouldCreate = $this->option('create');

        $provider = $this->option('provider');

        $this->info("Starting scalping for {$origin} -> {$destination} on {$date}...");

        if ($provider && $provider !== 'all') {
            $results = $scalper->searchProvider($provider, $origin, $destination, $date);
        } else {
            $results = $scalper->searchAll($origin, $destination, $date);
        }

        if (empty($results)) {
            $this->warn("No results found.");
            return;
        }

        $this->info("Found " . count($results) . " results:");

        $headers = ['Provider', 'Operator', 'Dep', 'Arr', 'Price', 'Suggested Sacco', 'Deep Link', 'Status'];
        $rows = [];

        foreach ($results as $res) {
            $suggestion = $res['suggested_sacco_id']
                ? "{$res['suggested_sacco_id']} (" . round($res['match_confidence']) . "%)"
                : '-';

            $status = '-';

            if ($shouldCreate && $res['suggested_sacco_id'] && $res['match_confidence'] >= 90) {
                // Simulate request to controller logic or call service directly
                // For now, we'll just mark it as "Would Create" to be safe, or we can duplicate the controller logic here.
                // Given the user wants it to work, let's call the approval logic if we can, or just instruct them to use the UI.
                // Actually, let's replicate the basic creation logic here for the command line.

                try {
                    $this->createSafari($res, $res['suggested_sacco_id'], $origin, $destination);
                    $status = 'Created';
                } catch (\Exception $e) {
                    $status = 'Error: ' . $e->getMessage();
                }
            } elseif ($shouldCreate) {
                $status = 'Skipped (Low Confidence)';
            }

            $rows[] = [
                $res['provider'],
                $res['operator_name'],
                $res['departure_time'],
                $res['arrival_time'],
                $res['price'] . ' ' . $res['currency'],
                $suggestion,
                $res['deep_link'],
                $status
            ];
        }

        $this->table($headers, $rows);
    }

    protected function createSafari($safariData, $saccoId, $searchedOrigin, $searchedDestination)
    {
        // Basic creation logic mirroring the controller
        // ... (Implementation of creation logic)
        // For brevity in this tool call, I will implement a simplified version or call a service method if it existed.
        // Since the logic is in the controller, it's best to move it to a service.
        // But for now, I'll just add the method to the command.

        \Illuminate\Support\Facades\DB::transaction(function () use ($safariData, $saccoId, $searchedOrigin, $searchedDestination) {
            $title = "{$safariData['operator_name']}: {$searchedOrigin} - {$searchedDestination}";

            $bookable = \App\Models\Bookable::create([
                'type' => 'sacco_safari',
                'title' => $title,
                'organizer_id' => 1, // Assign to Super Admin (ID 1) or a system user
                'sacco_id' => $saccoId,
                'status' => 'published',
                'currency' => $safariData['currency'],
                'metadata' => [
                    'source' => 'scalper_cli',
                    'provider' => $safariData['provider'],
                ]
            ]);

            $routeLabel = null;
            if (
                strtolower($safariData['origin']) !== strtolower($searchedOrigin) ||
                strtolower($safariData['destination']) !== strtolower($searchedDestination)
            ) {
                $routeLabel = "Route: {$safariData['origin']} to {$safariData['destination']}";
            }

            \App\Models\SaccoSafariInstance::create([
                'bookable_id' => $bookable->id,
                'sacco_id' => $saccoId,
                'departure_time' => $safariData['departure_time'],
                'arrival_time' => $safariData['arrival_time'],
                'inventory' => $safariData['available_seats'],
                'available_seats' => $safariData['available_seats'],
                'route_label' => $routeLabel,
                'metadata' => [
                    'deep_link' => $safariData['deep_link'],
                    'operator_name' => $safariData['operator_name'],
                    'price' => $safariData['price'],
                    'origin' => $safariData['origin'],
                    'destination' => $safariData['destination'],
                    'searched_origin' => $searchedOrigin,
                    'searched_destination' => $searchedDestination,
                ]
            ]);

            $bookable->ticketTiers()->create([
                'name' => 'Standard',
                'description' => 'Standard seat',
                'price' => $safariData['price'],
                'currency' => $safariData['currency'],
                'total_quantity' => $safariData['available_seats'],
                'remaining_quantity' => $safariData['available_seats'],
                'min_per_order' => 1,
                'max_per_order' => 10,
                'sales_start' => now(),
            ]);
        });
    }
}
