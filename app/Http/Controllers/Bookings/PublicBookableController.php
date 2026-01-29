<?php

namespace App\Http\Controllers\Bookings;

use App\Http\Controllers\Controller;
use App\Models\Bookable;
use App\Models\SaccoSafariInstance;
use App\Support\TembeaTourPresenter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class PublicBookableController extends Controller
{
    use TembeaTourPresenter;

    public function tourEvents(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 12);
        $perPage = max(1, min($perPage, 50));

        $query = Bookable::query()
            ->where('type', 'tour_event')
            ->where('status', 'published')
            ->with(['tourEvent', 'media', 'organizer.tembeaOperatorProfile'])
            ->withMin('ticketTiers', 'price')
            ->withCount('bookings');

        if (!$request->boolean('include_past')) {
            $query->where('starts_at', '>=', now());
        }

        // Text Search (q)
        if ($q = $request->query('q')) {
            $query->where(function ($query) use ($q) {
                $query->where('title', 'like', "%{$q}%")
                    ->orWhereHas('organizer.tembeaOperatorProfile', function ($qOp) use ($q) {
                        $qOp->where('company_name', 'like', "%{$q}%");
                    })
                    ->orWhereHas('tourEvent', function ($qTour) use ($q) {
                        $qTour->whereRaw('LOWER(destination) LIKE ?', ["%" . strtolower($q) . "%"])
                            ->orWhereRaw('LOWER(meeting_point) LIKE ?', ["%" . strtolower($q) . "%"]);
                    });
            });
        }

        // Geospatial Search: Destination (200km radius)
        if ($request->has(['destination_lat', 'destination_lng'])) {
            $lat = (float) $request->query('destination_lat');
            $lng = (float) $request->query('destination_lng');
            $radius = 200; // km

            $candidates = \App\Models\TourEvent::whereNotNull('destination')->get(['id', 'bookable_id', 'destination']);
            $matchingBookableIds = [];

            foreach ($candidates as $event) {
                $destinations = $event->destination;
                if (!is_array($destinations))
                    continue;

                foreach ($destinations as $dest) {
                    if (isset($dest['lat'], $dest['lng'])) {
                        $dist = $this->haversineGreatCircleDistance($lat, $lng, (float) $dest['lat'], (float) $dest['lng']);
                        if ($dist <= $radius) {
                            $matchingBookableIds[] = $event->bookable_id;
                            break;
                        }
                    }
                }
            }
            $query->whereIn('id', $matchingBookableIds);
        }

        // Geospatial Search: Meeting Point (50km radius)
        if ($request->has(['meeting_point_lat', 'meeting_point_lng'])) {
            $lat = (float) $request->query('meeting_point_lat');
            $lng = (float) $request->query('meeting_point_lng');
            $radius = 50; // km

            $candidates = \App\Models\TourEvent::whereNotNull('meeting_point')->get(['id', 'bookable_id', 'meeting_point']);
            $matchingBookableIds = [];

            foreach ($candidates as $event) {
                $points = $event->meeting_point;
                if (!is_array($points))
                    continue;

                foreach ($points as $point) {
                    if (isset($point['lat'], $point['lng'])) {
                        $dist = $this->haversineGreatCircleDistance($lat, $lng, (float) $point['lat'], (float) $point['lng']);
                        if ($dist <= $radius) {
                            $matchingBookableIds[] = $event->bookable_id;
                            break;
                        }
                    }
                }
            }
            $query->whereIn('id', $matchingBookableIds);
        }

        if ($theme = $request->query('theme')) {
            $query->where(function ($q) use ($theme) {
                $q->whereJsonContains('metadata->themes', $theme)
                    ->orWhereHas('tourEvent', fn($tourQuery) => $tourQuery->whereJsonContains('metadata->themes', $theme));
            });
        }

        if ($month = $request->query('month')) {
            $query->where(function ($q) use ($month) {
                $q->whereJsonContains('metadata->available_months', $month)
                    ->orWhereHas('tourEvent', fn($tourQuery) => $tourQuery->whereJsonContains('metadata->available_months', $month));
            });
        }

        if ($duration = $request->query('duration')) {
            $query->where(function ($q) use ($duration) {
                $q->where('metadata->duration_bucket', $duration)
                    ->orWhereHas('tourEvent', fn($tourQuery) => $tourQuery->where('metadata->duration_bucket', $duration));
            });
        }

        if ($priceMin = $request->query('price_min')) {
            $query->whereHas('ticketTiers', fn($tierQuery) => $tierQuery->where('price', '>=', (float) $priceMin));
        }

        if ($priceMax = $request->query('price_max')) {
            $query->whereHas('ticketTiers', fn($tierQuery) => $tierQuery->where('price', '<=', (float) $priceMax));
        }

        if ($categories = $request->query('categories')) {
            $categories = is_array($categories) ? $categories : explode(',', $categories);
            $query->where(function ($q) use ($categories) {
                foreach ($categories as $category) {
                    $q->orWhereJsonContains('tourEvent.categories', $category);
                }
            });
        }

        $sort = $request->query('sort');
        if ($sort === 'price') {
            $query->orderBy('ticket_tiers_min_price');
        } elseif ($sort === 'popular') {
            $query->orderByDesc('bookings_count');
        } elseif ($sort === 'upcoming') {
            $query->orderBy('starts_at');
        } else {
            $query->orderByDesc('is_featured')->orderByDesc('starts_at');
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        return response()->json(
            $paginator->through(fn(Bookable $bookable) => $this->summarizeTour($bookable))
        );
    }

    public function showTourEvent(string $slug): JsonResponse
    {
        $bookable = Bookable::where('slug', $slug)
            ->where('type', 'tour_event')
            ->where('status', 'published')
            ->with(['tourEvent', 'ticketTiers', 'media', 'organizer.tembeaOperatorProfile'])
            ->firstOrFail();

        return response()->json($this->presentTourEventDetail($bookable));
    }

    public function safariDetail(int $id): JsonResponse
    {
        $bookable = Bookable::query()
            ->whereIn('type', ['sacco_safari', 'safari'])
            ->where('status', 'published')
            ->with([
                'ticketTiers',
                'media',
                'sacco.tier',
                'safari.sacco.tier',
                'safari.saccoRoute.route',
                'safari',
            ])
            ->findOrFail($id);

        $instance = $bookable->safari;

        if (!$instance) {
            abort(404, 'Safari instance not found for this bookable');
        }

        $summary = $this->summarizeSafari($instance);

        $summary['bookable']['description'] = $bookable->description;
        $summary['bookable']['metadata'] = $bookable->metadata;
        $summary['bookable']['starts_at'] = optional($bookable->starts_at)->toIso8601String();
        $summary['bookable']['ends_at'] = optional($bookable->ends_at)->toIso8601String();
        $summary['safari']['seat_map'] = $instance->seat_map;
        $summary['safari']['metadata'] = $instance->metadata;
        $summary['safari']['trip_id'] = $instance->trip_id;
        $summary['safari']['vehicle_id'] = $instance->vehicle_id;

        return response()->json($summary);
    }

    public function safariOptions(): JsonResponse
    {
        $instances = SaccoSafariInstance::query()
            ->whereHas('bookable', fn($q) => $q->where('status', 'published'))
            ->with('saccoRoute.route')
            ->get();

        $towns = [];
        $routes = [];

        foreach ($instances as $instance) {
            $route = $instance->saccoRoute?->route;

            // Fallback to metadata for scalped items
            $origin = $route?->route_start_stop ?? $instance->metadata['origin'] ?? null;
            $destination = $route?->route_end_stop ?? $instance->metadata['destination'] ?? null;

            if (!$origin || !$destination) {
                continue;
            }

            foreach ([$origin, $destination] as $town) {
                $key = Str::lower($town);
                $towns[$key] = [
                    'value' => $town,
                    'label' => $town,
                ];
            }

            $key = Str::lower($origin) . '|' . Str::lower($destination);
            if (!isset($routes[$key])) {
                $routes[$key] = [
                    'origin' => $origin,
                    'destination' => $destination,
                    'label' => $origin . ' → ' . $destination,
                    'count' => 0,
                ];
            }

            $routes[$key]['count']++;
        }

        $towns = collect($towns)
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $popularRoutes = collect($routes)
            ->shuffle()
            ->values()
            ->take(3)
            ->all();

        return response()->json([
            'towns' => $towns,
            'popular_routes' => $popularRoutes,
        ]);
    }

    public function searchSafaris(Request $request): JsonResponse
    {
        $isSaccoSearch = $request->filled('sacco_id');

        $rules = [
            'travel_date' => $isSaccoSearch ? ['required', 'date'] : ['nullable', 'date'],
            'origin' => $isSaccoSearch ? ['nullable', 'string'] : ['required', 'string'],
            'destination' => $isSaccoSearch ? ['nullable', 'string'] : ['required', 'string'],
            'sacco_id' => ['nullable', 'string', 'exists:saccos,sacco_id'],
        ];

        $data = $request->validate($rules);

        $query = SaccoSafariInstance::query()
            ->whereHas('bookable', fn($q) => $q->whereIn('type', ['sacco_safari', 'safari'])->where('status', 'published'))
            ->with([
                'bookable.ticketTiers',
                'bookable.media',
                'sacco.tier',
                'saccoRoute.route',
            ])
            ->orderBy('departure_time');

        // Filter by Sacco
        if (!empty($data['sacco_id'])) {
            $query->where('sacco_id', $data['sacco_id']);
        }

        // Filter by Origin/Destination (if provided)
        if (!empty($data['origin']) && !empty($data['destination'])) {
            $origin = $data['origin'];
            $destination = $data['destination'];

            $query->where(function ($q) use ($origin, $destination) {
                // 1. Match standard route
                $q->whereHas('saccoRoute.route', function ($routeQuery) use ($origin, $destination) {
                    $routeQuery->where('route_start_stop', $origin)
                        ->where('route_end_stop', $destination);
                })
                    // 2. Match metadata searched_origin/destination (for scalped routes)
                    ->orWhere(function ($sub) use ($origin, $destination) {
                        $sub->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.searched_origin')) = ?", [$origin])
                            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.searched_destination')) = ?", [$destination]);
                    })
                    // 3. Match metadata origin/destination (fallback)
                    ->orWhere(function ($sub) use ($origin, $destination) {
                        $sub->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.origin')) = ?", [$origin])
                            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.destination')) = ?", [$destination]);
                    });
            });
        }

        // Filter by Date
        if (!empty($data['travel_date'])) {
            $travelDate = Carbon::parse($data['travel_date'])->toDateString();
            $query->whereDate('departure_time', $travelDate);
        }

        // Apply Limit for Sacco Search
        if ($isSaccoSearch) {
            $query->limit(10);
        }

        $results = $query
            ->get()
            ->map(fn(SaccoSafariInstance $instance) => $this->summarizeSafari($instance))
            ->values();

        return response()->json($results);
    }

    public function tourSuggestions(): JsonResponse
    {
        $tours = Bookable::query()
            ->where('type', 'tour_event')
            ->where('status', 'published')
            ->with(['tourEvent', 'media', 'organizer.tembeaOperatorProfile'])
            ->withMin('ticketTiers', 'price')
            ->withCount('bookings')
            ->get();

        $topDestinations = $tours
            ->filter(fn(Bookable $tour) => filled($tour->tourEvent?->destination))
            ->flatMap(function (Bookable $tour) {
                $destinations = $tour->tourEvent->destination;
                // Handle both array (new) and string (legacy) formats
                if (is_string($destinations)) {
                    return [$destinations];
                }
                if (is_array($destinations)) {
                    return collect($destinations)->pluck('name')->filter()->all();
                }
                return [];
            })
            ->groupBy(fn($destination) => $destination)
            ->map(fn($group, $destination) => [
                'label' => $destination,
                'value' => $destination,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->take(8)
            ->all();

        $trending = $tours
            ->shuffle()
            ->values()
            ->take(6)
            ->map(fn(Bookable $tour) => $this->summarizeTour($tour))
            ->all();

        $readyItineraries = $tours
            ->shuffle()
            ->values()
            ->take(6)
            ->map(function (Bookable $tour) {
                $summary = $this->summarizeTour($tour);
                return [
                    'title' => $tour->title,
                    'slug' => $tour->slug,
                    'duration_label' => data_get($summary, 'tour_event.duration_label'),
                    'price_from' => $summary['price_from'] ?? null,
                    'currency' => $summary['currency'] ?? null,
                    'summary' => Str::limit((string) ($tour->subtitle ?? strip_tags((string) $tour->description)), 120),
                    'hero_image' => $summary['media'][0]['url'] ?? null,
                ];
            })
            ->all();

        return response()->json([
            'top_destinations' => $topDestinations,
            'trending_tours' => $trending,
            'ready_itineraries' => $readyItineraries,
        ]);
    }

    private function summarizeSafari(SaccoSafariInstance $instance): array
    {
        $bookable = $instance->bookable;
        $ticketTiers = collect($bookable?->ticketTiers ?? []);
        $media = collect($bookable?->media ?? [])
            ->map(fn($attachment) => [
                'id' => $attachment->id,
                'type' => $attachment->type,
                'url' => $attachment->url,
                'title' => $attachment->title,
                'alt_text' => $attachment->alt_text,
                'position' => $attachment->position,
            ])
            ->values()
            ->all();

        $tier = $instance->sacco?->tier;
        $route = $instance->saccoRoute?->route;

        $priceFrom = $ticketTiers->min('price');

        return [
            'bookable_id' => $bookable?->id,
            'bookable' => [
                'id' => $bookable?->id,
                'title' => $bookable?->title,
                'subtitle' => $bookable?->subtitle,
                'currency' => $bookable?->currency,
                'price_from' => $priceFrom !== null ? (float) $priceFrom : null,
                'media' => $media,
            ],
            'sacco' => $instance->sacco ? [
                'id' => $instance->sacco->sacco_id,
                'name' => $instance->sacco->sacco_name,
                'logo' => $instance->sacco->sacco_logo,
                'tier' => $tier ? [
                    'name' => $tier->name,
                    'features' => $tier->features,
                ] : null,
            ] : null,
            'route' => ($route || (isset($instance->metadata['origin']) && isset($instance->metadata['destination']))) ? [
                'id' => $instance->sacco_route_id,
                'origin' => $instance->metadata['searched_origin'] ?? $instance->metadata['origin'] ?? $route->route_start_stop,
                'destination' => $instance->metadata['searched_destination'] ?? $instance->metadata['destination'] ?? $route->route_end_stop,
                'peak_fare' => $instance->saccoRoute?->peak_fare,
                'off_peak_fare' => $instance->saccoRoute?->off_peak_fare,
                'currency' => $instance->saccoRoute?->currency ?? 'KES',
            ] : null,
            'safari' => [
                'id' => $instance->id,
                'departure_time' => optional($instance->departure_time)->toIso8601String(),
                'arrival_time' => optional($instance->arrival_time)->toIso8601String(),
                'available_seats' => $instance->available_seats,
                'inventory' => $instance->inventory,
                'route_label' => $instance->route_label,
            ],
            'ticket_tiers' => $ticketTiers
                ->map(fn($tierItem) => [
                    'id' => $tierItem->id,
                    'name' => $tierItem->name,
                    'description' => $tierItem->description,
                    'price' => $tierItem->price,
                    'currency' => $tierItem->currency,
                    'remaining_quantity' => $tierItem->remaining_quantity,
                ])
                ->values()
                ->all(),
        ];
    }
    /**
     * Calculates the great-circle distance between two points, with
     * the Haversine formula.
     * @param float $latitudeFrom Latitude of start point in [deg decimal]
     * @param float $longitudeFrom Longitude of start point in [deg decimal]
     * @param float $latitudeTo Latitude of target point in [deg decimal]
     * @param float $longitudeTo Longitude of target point in [deg decimal]
     * @param float $earthRadius Mean earth radius in [km]
     * @return float Distance between points in [km] (same as earthRadius)
     */
    private function haversineGreatCircleDistance(
        $latitudeFrom,
        $longitudeFrom,
        $latitudeTo,
        $longitudeTo,
        $earthRadius = 6371
    ) {
        // convert from degrees to radians
        $latFrom = deg2rad($latitudeFrom);
        $lonFrom = deg2rad($longitudeFrom);
        $latTo = deg2rad($latitudeTo);
        $lonTo = deg2rad($longitudeTo);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
        return $angle * $earthRadius;
    }
}
