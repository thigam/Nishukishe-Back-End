<?php

namespace App\Http\Controllers\Bookings;

use App\Http\Controllers\Controller;
use App\Models\Bookable;
use App\Services\Bookings\BookableManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ManagerBookableController extends Controller
{
    public function __construct(private readonly BookableManager $bookableManager)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Bookable::query()->where('organizer_id', Auth::id());

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $bookables = $query->with(['ticketTiers', 'media', 'primaryPayoutProfile', 'safari', 'tourEvent'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($bookables);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $bookable = $this->bookableManager->createBookable($data, $request->user());

        return response()->json($bookable, 201);
    }

    public function show(Bookable $bookable): JsonResponse
    {
        $this->authorizeBookable($bookable);

        return response()->json($bookable->load(['ticketTiers', 'media', 'primaryPayoutProfile', 'safari', 'tourEvent']));
    }

    public function update(Request $request, Bookable $bookable): JsonResponse
    {
        $this->authorizeBookable($bookable);
        $data = $this->validatePayload($request, $bookable->type);
        $updated = $this->bookableManager->updateBookable($bookable, $data);

        return response()->json($updated);
    }

    public function publish(Bookable $bookable): JsonResponse
    {
        $this->authorizeBookable($bookable);
        $published = $this->bookableManager->publish($bookable);

        return response()->json($published);
    }

    public function analytics(Bookable $bookable): JsonResponse
    {
        $this->authorizeBookable($bookable);

        $bookable->load(['tickets', 'bookings.payments']);

        $ticketsSold = $bookable->tickets->count();
        $ticketsScanned = $bookable->tickets->whereNotNull('scanned_at')->count();
        $gross = $bookable->bookings->sum('total_amount');
        $serviceFees = $bookable->bookings->sum('service_fee_amount');
        $net = $bookable->bookings->sum('net_amount');

        return response()->json([
            'tickets_sold' => $ticketsSold,
            'tickets_scanned' => $ticketsScanned,
            'gross_revenue' => $gross,
            'service_fees' => $serviceFees,
            'net_revenue' => $net,
        ]);
    }

    public function destroy(Bookable $bookable): JsonResponse
    {
        $this->authorizeBookable($bookable);
        $bookable->delete();
        return response()->json(['message' => 'Bookable deleted successfully']);
    }

    protected function validatePayload(Request $request, ?string $type = null): array
    {
        $type = $type ?? $request->input('type');
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:sacco_safari,tour_event'],
            'currency' => ['nullable', 'string', 'size:3'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'service_fee_rate' => ['nullable', 'numeric'],
            'service_fee_flat' => ['nullable', 'numeric'],
            'is_featured' => ['nullable', 'boolean'],
            'ticket_tiers' => ['required', 'array', 'min:1'],
            'ticket_tiers.*.id' => ['nullable', 'integer'],
            'ticket_tiers.*.name' => ['required', 'string'],
            'ticket_tiers.*.price' => ['required', 'numeric', 'min:0'],
            'ticket_tiers.*.total_quantity' => ['required', 'integer', 'min:1'],
            'media' => ['nullable', 'array'],
            'media.*.url' => ['required', 'url'],
            'payout_profile.payout_type' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];

        if ($type === 'sacco_safari') {
            $rules = array_merge($rules, [
                'sacco_id' => ['required', 'integer'],
                'sacco_route_id' => ['nullable', 'integer'],
                'trip_id' => ['nullable', 'integer'],
                'vehicle_id' => ['nullable', 'integer'],
                'departure_time' => ['required', 'date'],
                'arrival_time' => ['nullable', 'date'],
                'seating_configuration' => ['nullable', 'array'],
            ]);
        }

        if ($type === 'tour_event') {
            $rules = array_merge($rules, [
                'destination' => ['required', 'array'],
                'destination.*.name' => ['required', 'string'],
                'destination.*.display_name' => ['nullable', 'string'],
                'destination.*.coordinates' => ['nullable', 'array'],
                'destination.*.coordinates.lat' => ['required_with:destination.*.coordinates', 'numeric'],
                'destination.*.coordinates.lng' => ['required_with:destination.*.coordinates', 'numeric'],
                'meeting_point' => ['nullable', 'array'],
                'meeting_point.*.name' => ['required', 'string'],
                'meeting_point.*.display_name' => ['nullable', 'string'],
                'meeting_point.*.coordinates' => ['nullable', 'array'],
                'meeting_point.*.coordinates.lng' => ['required_with:meeting_point.*.coordinates', 'numeric'],
                'categories' => ['nullable', 'array'],
                'categories.*' => ['string'],
                'duration_label' => ['nullable', 'string'],
                'marketing_copy' => ['nullable', 'string'],
                'path_geojson' => ['nullable'],
                'stops' => ['nullable'],
                'checkout_type' => ['nullable', 'in:tembea,external'],
                'contact_info' => ['nullable', 'array'],
            ]);
        }

        $validator = Validator::make($request->all(), $rules);
        $validator->validate();

        $validated = $validator->validated();

        // Map metadata.images to media if media is not provided
        if (empty($validated['media']) && !empty($validated['metadata']['images']) && is_array($validated['metadata']['images'])) {
            $validated['media'] = array_map(function ($url) {
                return ['url' => $url, 'type' => 'image'];
            }, $validated['metadata']['images']);
        }

        return $validated;
    }

    protected function authorizeBookable(Bookable $bookable): void
    {
        if ($bookable->organizer_id !== Auth::id()) {
            throw new AccessDeniedHttpException('You are not allowed to manage this bookable.');
        }
    }
}
