<?php

namespace App\Console\Commands;

use App\Models\Bookable;
use App\Models\Sacco;
use App\Models\SaccoSafariInstance;
use App\Models\TicketTier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateTestSafaris extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'safaris:seed-test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates 10 test safaris on March 1st for Janam, Chania Genesis, Mash Poa';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $targetDate = Carbon::create(2026, 3, 1, 8, 0, 0);

        // Delete previously seeded safaris for this date
        $oldSafaris = Bookable::where('type', 'safari')->whereDate('starts_at', '2026-03-01')->get();
        foreach ($oldSafaris as $old) {
            SaccoSafariInstance::where('bookable_id', $old->id)->delete();
            TicketTier::where('bookable_id', $old->id)->delete();
            $old->forceDelete();
        }

        $saccos = [
            'Janam Sacco' => 3,
            'Chania Genesis' => 3,
            'Mash Poa' => 4,
        ];

        $routes = [
            ['Route: Nairobi to Mombasa', 'Nairobi - Mombasa', 'Nairobi', 'Mombasa'],
            ['Route: Mombasa to Busia', 'Mombasa - Busia', 'Mombasa', 'Busia'],
            ['Route: Mombasa to Kisumu', 'Mombasa - Kisumu', 'Mombasa', 'Kisumu'],
        ];

        $withSeatingArray = [true, true, true, true, true, false, false, false, false, false];
        shuffle($withSeatingArray);

        $seatingConfig = [
            'type' => 'bus_2x2',
            'total_seats' => 45,
            'layout' => [
                'columns' => [
                    ['id' => '1', 'name' => 'A'],
                    ['id' => '2', 'name' => 'B'],
                    ['id' => 'aisle', 'name' => ''],
                    ['id' => '3', 'name' => 'C'],
                    ['id' => '4', 'name' => 'D'],
                ],
                'rows' => [
                    ['id' => '1', 'name' => '1'],
                    ['id' => '2', 'name' => '2'],
                    ['id' => '3', 'name' => '3'],
                    ['id' => '4', 'name' => '4'],
                    ['id' => '5', 'name' => '5'],
                    ['id' => '6', 'name' => '6'],
                    ['id' => '7', 'name' => '7'],
                    ['id' => '8', 'name' => '8'],
                    ['id' => '9', 'name' => '9'],
                    ['id' => '10', 'name' => '10'],
                    ['id' => '11', 'name' => '11'],
                ],
                'unavailable' => ['11A', '11D']
            ]
        ];

        $index = 0;

        foreach ($saccos as $saccoName => $count) {
            $sacco = Sacco::where('sacco_name', 'like', "%{$saccoName}%")->first();
            if (!$sacco) {
                // Determine a base sacco_id that adheres to database constraints (max 20 chars perhaps?)
                $saccoId = Str::upper(Str::random(8));
                $sacco = Sacco::create([
                    'sacco_id' => $saccoId,
                    'sacco_name' => collect(explode(' ', $saccoName))->first(),
                    'vehicle_type' => 'Bus',
                    'join_date' => now(),
                    'is_approved' => true,
                ]);
                $sacco->sacco_name = $saccoName;
                $sacco->save();
            }

            for ($i = 0; $i < $count; $i++) {
                $routePair = $routes[array_rand($routes)];
                $routeLabel = $routePair[0];
                $titleSuffix = $routePair[1];
                $origin = $routePair[2];
                $destination = $routePair[3];

                $departureTime = $targetDate->copy()->addHours(rand(0, 10));

                $organizer = \App\Models\User::first();
                if (!$organizer) {
                    $organizer = \App\Models\User::create([
                        'name' => 'System Organizer',
                        'email' => 'admin@test.com',
                        'password' => bcrypt('password'),
                    ]);
                }

                $bookable = Bookable::create([
                    'organizer_id' => $organizer->id,
                    'type' => 'safari',
                    'title' => "{$saccoName} {$titleSuffix}",
                    'subtitle' => "Express travel with {$saccoName}",
                    'description' => "Enjoy a comfortable ride from {$origin} to {$destination} on March 1st. We ensure your safety and comfort throughout the journey.",
                    'status' => 'published',
                    'currency' => 'KES',
                    'service_fee_rate' => 0.0,
                    'service_fee_flat' => 50,
                    'published_at' => now(),
                    'starts_at' => $departureTime,
                    'ends_at' => $departureTime->copy()->addHours(8),
                    'is_featured' => true,
                    'sacco_id' => $sacco->sacco_id,
                ]);

                $hasSeating = $withSeatingArray[$index];

                TicketTier::create([
                    'bookable_id' => $bookable->id,
                    'name' => 'Standard Ticket',
                    'description' => 'Regular seating ticket',
                    'currency' => 'KES',
                    'price' => 10,
                    'total_quantity' => 45,
                    'remaining_quantity' => 45,
                    'min_per_order' => 1,
                    'max_per_order' => 10,
                ]);

                SaccoSafariInstance::create([
                    'bookable_id' => $bookable->id,
                    'sacco_id' => $sacco->sacco_id,
                    'departure_time' => $departureTime,
                    'arrival_time' => $departureTime->copy()->addHours(8),
                    'inventory' => 45,
                    'available_seats' => 45,
                    'route_label' => $routeLabel,
                    'seating_configuration' => $hasSeating ? $seatingConfig : null,
                    'metadata' => [
                        'origin' => $origin,
                        'destination' => $destination,
                        'searched_origin' => $origin,
                        'searched_destination' => $destination,
                    ],
                ]);

                $this->info("Created Bookable #{$bookable->id} for {$saccoName} ({$titleSuffix}) - Seating: " . ($hasSeating ? 'Yes' : 'No'));
                $index++;
            }
        }

        $this->info("Successfully seeded 10 safaris for March 1st 2026!");
    }
}
