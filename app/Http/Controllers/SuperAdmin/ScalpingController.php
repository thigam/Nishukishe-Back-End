<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\Scalping\ScalpingService;
use Illuminate\Http\Request;
use App\Models\SaccoSafariInstance;
use App\Models\Sacco;
use App\Models\Trip;
use App\Models\Bookable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScalpingController extends Controller
{
    protected $scalper;

    public function __construct(ScalpingService $scalper)
    {
        $this->scalper = $scalper;
    }

    public function search(Request $request)
    {
        $request->validate([
            'routes' => 'required|array',
            'routes.*.origin' => 'required|string',
            'routes.*.destination' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $routes = $request->input('routes');
        $startDate = \Carbon\Carbon::parse($request->input('start_date'));
        $endDate = \Carbon\Carbon::parse($request->input('end_date'));
        $results = [];

        // Loop through dates
        for ($date = $startDate; $date->lte($endDate); $date->addDay()) {
            $dateStr = $date->format('Y-m-d');

            // Loop through routes
            foreach ($routes as $route) {
                $origin = $route['origin'];
                $destination = $route['destination'];

                try {
                    $dayResults = $this->scalper->searchAll($origin, $destination, $dateStr);
                    // Inject searched origin/destination into results
                    foreach ($dayResults as &$res) {
                        $res['searched_origin'] = $origin;
                        $res['searched_destination'] = $destination;
                    }
                    $results = array_merge($results, $dayResults);
                } catch (\Exception $e) {
                    Log::error("Bulk search failed for {$origin}-{$destination} on {$dateStr}: " . $e->getMessage());
                }
            }
        }

        return response()->json($results);
    }

    public function approve(Request $request)
    {
        $request->validate([
            'safari' => 'required|array',
            'sacco_id' => 'required|string|exists:saccos,sacco_id',
        ]);

        $safariData = $request->input('safari');
        $saccoId = $request->input('sacco_id');
        $searchedOrigin = $request->input('searched_origin', $safariData['origin']);
        $searchedDestination = $request->input('searched_destination', $safariData['destination']);

        Log::info("Approving Safari: {$safariData['origin']} -> {$safariData['destination']} for Sacco: {$saccoId}");
        Log::info("Safari Data: " . json_encode($safariData));

        try {
            DB::beginTransaction();

            // 1. Create Bookable
            // Use searched origin/destination for the title so it matches what the user was looking for
            $title = "{$safariData['operator_name']}: {$searchedOrigin} - {$searchedDestination}";

            $bookable = Bookable::create([
                'type' => 'sacco_safari',
                'title' => $title,
                'organizer_id' => $request->user()->id,
                'sacco_id' => $saccoId,
                'status' => 'published', // Or 'draft'
                'currency' => $safariData['currency'],
                'metadata' => [
                    'source' => 'scalper',
                    'provider' => $safariData['provider'],
                ]
            ]);

            // Determine route label if actual route differs from searched route
            $routeLabel = null;
            if (
                strtolower($safariData['origin']) !== strtolower($searchedOrigin) ||
                strtolower($safariData['destination']) !== strtolower($searchedDestination)
            ) {
                $routeLabel = "Route: {$safariData['origin']} to {$safariData['destination']}";
            }

            // 2. Create SaccoSafariInstance
            $safari = SaccoSafariInstance::create([
                'bookable_id' => $bookable->id,
                'sacco_id' => $saccoId,
                'sacco_route_id' => null, // No route geometry for scalped items yet
                'trip_id' => null, // Optional: could create a Trip record if we want to persist schedule
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

            // 3. Create Ticket Tier (Price)
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

            DB::commit();

            return response()->json(['message' => 'Safari approved successfully', 'safari' => $safari]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to approve safari: " . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json(['message' => 'Failed to approve safari: ' . $e->getMessage()], 500);
        }
    }

    public function stops(): \Illuminate\Http\JsonResponse
    {
        $stops = [
            "Nairobi",
            "Mombasa",
            "Kisumu",
            "Nakuru",
            "Eldoret",
            "Thika",
            "Malindi",
            "Kitale",
            "Garissa",
            "Kakamega",
            "Nyeri",
            "Meru",
            "Kericho",
            "Nanyuki",
            "Machakos",
            "Kisii",
            "Mumias",
            "Vihiga",
            "Bungoma",
            "Busia",
            "Migori",
            "Embu",
            "Homa Bay",
            "Isiolo",
            "Kitui",
            "Lamu",
            "Marsabit",
            "Mwingi",
            "Naivasha",
            "Narok",
            "Nyahururu",
            "Siaya",
            "Voi",
            "Wajir",
            "Webuye",
            "Wote",
            "Kilifi",
            "Mariakani",
            "Mtwapa",
            "Ukunda",
            "Chuka",
            "Limuru",
            "Kimilili",
            "Sotik",
            "Homabay",
            "Rongo",
            "Awendo",
            "Oyugis",
            "Mbita",
            "Kendu Bay",
            "Maseno",
            "Luanda",
            "Yala",
            "Ugunja",
            "Bondo",
            "Usenge",
            "Port Victoria",
            "Malaba",
            "Kapsabet",
            "Iten",
            "Kabarnet",
            "Maralal",
            "Lodwar",
            "Lokichogio",
            "Moyale",
            "Mandera",
            "Elwak",
            "Hola",
            "Garsen",
            "Lunga Lunga",
            "Taveta",
            "Oloitokitok",
            "Namanga",
            "Kajiado",
            "Kitengela",
            "Athi River",
            "Mlolongo",
            "Ruiru",
            "Juja",
            "Kenol",
            "Murang'a",
            "Karatina",
            "Chogoria",
            "Maua",
            "Isiolo",
            "Nkubu",
            "Runyenjes",
            "Kerugoya",
            "Kutus",
            "Mwea",
            "Makindu",
            "Kibwezi",
            "Mtito Andei",
            "Sultan Hamud",
            "Emali",
            "Loitokitok",
            "Bomet",
            "Litein",
            "Kaplong",
            "Narok",
            "Mai Mahiu",
            "Gilgil",
            "Molo",
            "Njoro",
            "Subukia",
            "Rumuruti"
        ];

        sort($stops);

        return response()->json(array_values(array_unique($stops)));
    }
}
