<?php

namespace App\Http\Controllers;
use App\Models\Bookable;
use App\Models\Sacco;
use App\Models\User;
use App\Models\SaccoManager;
use App\Models\Vehicle;
use App\Models\UserRole;
use App\Services\Bookings\BookableManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Middleware;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Events\UserRegistered;

class SaccoController extends Controller
{
    public function __construct(private readonly BookableManager $bookableManager)
    {
    }
    /**
     * Return all entries in Sacco.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        //all where sacco_email in user table  is_approved = 1
        // $saccos = Sacco::whereIn('sacco_email', function($query) {
        //     $query->select('email')
        //           ->from('users')
        //           ->where('is_approved', 1);
        // })->get();
        $saccos = Sacco::all();
        return response()->json($saccos);

    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q');
        if (!$query) {
            return response()->json([]);
        }

        $saccos = Sacco::whereRaw('LOWER(sacco_name) LIKE ?', ['%' . strtolower($query) . '%'])
            ->limit(8)
            ->get(['sacco_id', 'sacco_name']);

        return response()->json($saccos);
    }


    public function saccoRegister(Request $request)
    {
        Log::info('Sacco sign-up request received', ['request' => $request->all()]);
        // Ensure the request is parsed as JSON
        $request->merge(json_decode($request->getContent(), true) ?? []);


        if ($request->has('share_slug')) {
            $request->merge([
                'share_slug' => $this->normalizeShareSlug($request->input('share_slug')),
            ]);
        }

        // Validate incoming request: sacco model
        $validator = Validator::make($request->all(), [
            'sacco_name' => 'required|string|max:255',
            'sacco_location' => 'required|string|max:255',
            'sacco_phone' => 'required|string|unique:saccos',
            'sacco_email' => 'required|email|unique:saccos',
            'sacco_website' => 'nullable|url',
            // 'sacco_logo' => 'nullable|image',
            'sacco_logo' => 'nullable|string|max:255',
            'vehicle_type' => 'required|string|max:255',
            'till_number' => 'nullable|string|max:20',
            'paybill_number' => 'nullable|string|max:20',
            'profile_headline' => 'nullable|string|max:255',
            'profile_description' => 'nullable|string',
            'share_slug' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('saccos', 'share_slug')],
            'profile_contact_name' => 'nullable|string|max:255',
            'profile_contact_phone' => 'nullable|string|max:50',
            'profile_contact_email' => 'nullable|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if the sacco already exists
        $existingSacco = Sacco::where('sacco_name', $request->sacco_name)
            ->orWhere('sacco_phone', $request->sacco_phone)
            ->orWhere('sacco_email', $request->sacco_email)
            ->first();
        if ($existingSacco) {
            return response()->json([
                'message' => 'Sacco with this name, phone, or email already exists.'
            ], 409);
        }
        $saccoId = $this->generateSaccoId($request->sacco_name);

        // Create a new sacco
        $sacco = Sacco::create([
            'sacco_id' => $saccoId, // Generate a unique sacco ID
            'sacco_name' => $request->sacco_name,
            'sacco_location' => $request->sacco_location,
            'sacco_phone' => $request->sacco_phone,
            'sacco_email' => $request->sacco_email,
            'sacco_website' => $request->sacco_website,
            'vehicle_type' => $request->vehicle_type,
            'join_date' => now(),
            'sacco_logo' => $request->sacco_logo, // Assuming this is a string path or URL
            'till_number' => $request->till_number,
            'paybill_number' => $request->paybill_number,
            'is_approved' => false,
            'profile_headline' => $request->profile_headline,
            'profile_description' => $request->profile_description,
            'share_slug' => $request->share_slug,
            'profile_contact_name' => $request->profile_contact_name,
            'profile_contact_phone' => $request->profile_contact_phone,
            'profile_contact_email' => $request->profile_contact_email,
        ]);


        $user = User::where('email', $request->sacco_email)->first();
        if ($user) {
            return response()->json([
                'message' => 'User with this email already exists.'
            ], 409);
        }

        $randomPassword = Str::random(12);
        $user = User::create([
            'name' => $request->sacco_name,
            'email' => $request->sacco_email,
            'phone' => $request->sacco_phone,
            'role' => UserRole::SACCO,
            'password' => Hash::make(
                $randomPassword
            ),
            'is_verified' => false,
        ]);

        // \Log::info('Sacco registered successfully', ['email' => $sacco->sacco_email]);
        event(new UserRegistered($user));
        // \Log::info('SaccoRegistered event dispatched for sacco: ' . $sacco->sacco_email);



        return response()->json([
            'message' => 'Sacco successfully registered!',
            'sacco' => $sacco
        ], 201);

    }

    public function createPlaceholderSacco(Request $request): JsonResponse
    {
        $request->merge(json_decode($request->getContent(), true) ?? []);

        $validator = Validator::make($request->all(), [
            'sacco_name' => 'required|string|max:255',
            'sacco_location' => 'nullable|string|max:255',
            'vehicle_type' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $saccoName = $request->input('sacco_name');
        $saccoId = $this->generateSaccoId($saccoName);

        $slug = Str::slug($saccoName) ?: 'sacco';
        $email = $this->generateUniquePlaceholderEmail($slug);
        $phone = $this->generateUniquePlaceholderPhone();

        $sacco = Sacco::create([
            'sacco_id' => $saccoId,
            'sacco_name' => $saccoName,
            'sacco_location' => $request->input('sacco_location'),
            'sacco_phone' => $phone,
            'sacco_email' => $email,
            'sacco_website' => $request->input('sacco_website'),
            'vehicle_type' => $request->input('vehicle_type'),
            'join_date' => now(),
            'sacco_logo' => $request->input('sacco_logo'),
            'till_number' => $request->input('till_number'),
            'paybill_number' => $request->input('paybill_number'),
            'is_approved' => true,

        ]);

        return response()->json([
            'message' => 'Placeholder sacco created successfully.',
            'sacco' => $sacco,
        ], 201);
    }

    public function findById(string $id): JsonResponse
    {
        $sacco = Sacco::with('tier')->where('sacco_id', $id)->first();
        if ($sacco) {
            return response()->json($sacco);
        }
        return response()->json(['message' => 'Sacco not found'], 404);
    }
    public function findByName(string $name): JsonResponse
    {
        $sacco = Sacco::where('sacco_name', 'like', '%' . $name . '%')->get();
        if ($sacco->isNotEmpty()) {
            return response()->json($sacco);
        }
        return response()->json(['message' => 'Sacco not found'], 404);
    }

    public function findByPhone(string $phone): JsonResponse
    {
        $sacco = Sacco::where('sacco_phone', 'like', '%' . $phone . '%')->get();
        if ($sacco->isNotEmpty()) {
            return response()->json($sacco);
        }
        return response()->json(['message' => 'Sacco not found'], 404);
    }
    public function findByEmail(string $email): JsonResponse
    {
        $sacco = Sacco::where('sacco_email', 'like', '%' . $email . '%')->get();
        if ($sacco->isNotEmpty()) {
            return response()->json($sacco);
        }
        return response()->json(['message' => 'Sacco not found'], 404);
    }

    public function findByLocation(string $location): JsonResponse
    {
        $sacco = Sacco::where('sacco_location', 'like', '%' . $location . '%')->get();
        if ($sacco->isNotEmpty()) {
            return response()->json($sacco);
        }
        return response()->json(['message' => 'Sacco not found'], 404);
    }
    public function findByRoute(string $route): JsonResponse
    {
        $sacco = Sacco::where('sacco_routes', 'like', '%' . $route . '%')->get();
        if ($sacco->isNotEmpty()) {
            return response()->json($sacco);
        }
        return response()->json(['message' => 'Sacco not found'], 404);
    }

    /**
     * Get approved users associated with a sacco.
     */
    public function approvedPeople(string $saccoId): JsonResponse
    {
        $userIds = SaccoManager::where('sacco_id', $saccoId)->pluck('user_id');
        $users = User::whereIn('id', $userIds)->where('is_approved', true)->get();

        if ($users->isNotEmpty()) {
            return response()->json($users);
        }

        return response()->json(['message' => 'No approved people found for this sacco'], 404);
    }

    /**
     * Get vehicles belonging to a sacco.
     */
    public function vehicles(string $saccoId): JsonResponse
    {
        $vehicles = Vehicle::where('sacco_id', $saccoId)->get();
        if ($vehicles->isNotEmpty()) {
            return response()->json($vehicles);
        }

        return response()->json(['message' => 'No vehicles found for this sacco'], 404);
    }

    public function updateSacco(Request $request, string $id): JsonResponse
    {
        $sacco = Sacco::find($id);

        if (!$sacco) {
            return response()->json(['message' => 'Sacco not found'], 404);
        }

        if ($request->has('share_slug')) {
            $request->merge([
                'share_slug' => $this->normalizeShareSlug($request->input('share_slug')),
            ]);
        }

        $data = $request->validate($this->profileUpdateRules($sacco));

        $sacco->fill($data);
        $sacco->save();

        return response()->json($sacco->fresh());
    }

    public function updateProfile(Request $request, string $id): JsonResponse
    {
        $sacco = Sacco::findOrFail($id);
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($user->role !== UserRole::SUPER_ADMIN && $user->role !== UserRole::SACCO) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->role === UserRole::SACCO) {
            $managesSacco = SaccoManager::where('user_id', $user->id)
                ->where('sacco_id', $sacco->sacco_id)
                ->exists();

            $matchesSaccoEmail = strcasecmp($user->email ?? '', $sacco->sacco_email ?? '') === 0;

            if (!$managesSacco && !$matchesSaccoEmail) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        if ($request->has('share_slug')) {
            $request->merge([
                'share_slug' => $this->normalizeShareSlug($request->input('share_slug')),
            ]);
        }

        $data = $request->validate($this->profileUpdateRules($sacco));

        $sacco->fill($data);
        $sacco->save();

        return response()->json([
            'message' => 'Sacco profile updated successfully.',
            'sacco' => $sacco->fresh(),
        ]);
    }
    public function deleteSacco(string $id): JsonResponse
    {
        $sacco = Sacco::find($id);
        if ($sacco) {
            $sacco->delete();
            return response()->json(['message' => 'Sacco deleted successfully']);
        }
        return response()->json(['message' => 'Sacco not found'], 404);
    }
    public function getSaccoRoutes(string $sacco): JsonResponse
    {
        //return all routes for a specific sacco
        $routes = Sacco::where('sacco_name', $sacco)->first()->routes;
        if ($routes->isNotEmpty()) {
            return response()->json($routes);
        }
        return response()->json(['message' => 'No routes found for this sacco'], 404);
    }

    public function createSafari(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'sacco_id' => ['required', 'string', 'exists:saccos,sacco_id'],
            'sacco_route_id' => ['required', 'string', 'exists:sacco_routes,sacco_route_id'],
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'departure_time' => ['required', 'date'],
            'arrival_time' => ['nullable', 'date'],
            'inventory' => ['required', 'integer', 'min:1'],
            'ticket_tiers' => ['required', 'array', 'min:1'],
            'ticket_tiers.*.name' => ['required', 'string'],
            'ticket_tiers.*.price' => ['required', 'numeric', 'min:0'],
            'ticket_tiers.*.total_quantity' => ['required', 'integer', 'min:1'],
            'ticket_tiers.*.currency' => ['nullable', 'string', 'size:3'],
            'ticket_tiers.*.description' => ['nullable', 'string'],
            'ticket_tiers.*.sales_start' => ['nullable', 'date'],
            'ticket_tiers.*.sales_end' => ['nullable', 'date'],
            'media' => ['nullable', 'array'],
            'media.*.url' => ['required', 'url'],
            'media.*.title' => ['nullable', 'string', 'max:255'],
            'media.*.alt_text' => ['nullable', 'string', 'max:255'],
            'payout_profile' => ['nullable', 'array'],
            'payout_profile.payout_type' => ['nullable', 'string'],
            'payout_profile.phone_number' => ['nullable', 'string'],
            'payout_profile.till_number' => ['nullable', 'string'],
            'payout_profile.paybill_number' => ['nullable', 'string'],
            'payout_profile.account_name' => ['nullable', 'string'],
            'payout_profile.bank_name' => ['nullable', 'string'],
            'payout_profile.bank_branch' => ['nullable', 'string'],
            'payout_profile.bank_account_number' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'seat_map' => ['nullable', 'array'],
            'seating_configuration' => ['nullable', 'array'],
            'publish' => ['sometimes', 'boolean'],
        ]);

        $validator->validate();
        $payload = $validator->validated();

        $sacco = Sacco::with('tier')->findOrFail($payload['sacco_id']);

        $managesSacco = SaccoManager::where('user_id', $user->id)
            ->where('sacco_id', $sacco->sacco_id)
            ->exists();

        $matchesSaccoEmail = strcasecmp($user->email ?? '', $sacco->sacco_email ?? '') === 0;

        $isSuperAdmin = $user->role === UserRole::SUPER_ADMIN;

        if (!$isSuperAdmin && !$managesSacco && !$matchesSaccoEmail) {
            return response()->json(['message' => 'You can only create safaris for your sacco.'], 403);
        }

        if (!$this->tierAllowsSafaris($sacco)) {
            return response()->json(['message' => 'Your sacco tier does not include safari publishing.'], 403);
        }

        $bookablePayload = [
            'type' => 'sacco_safari',
            'title' => $payload['title'],
            'subtitle' => $payload['subtitle'] ?? null,
            'description' => $payload['description'] ?? null,
            'sacco_id' => $payload['sacco_id'],
            'starts_at' => $payload['departure_time'],
            'ends_at' => $payload['arrival_time'] ?? null,
            'ticket_tiers' => $payload['ticket_tiers'],
            'media' => $payload['media'] ?? [],
            'payout_profile' => $payload['payout_profile'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
            'sacco_route_id' => $payload['sacco_route_id'],
            'departure_time' => $payload['departure_time'],
            'arrival_time' => $payload['arrival_time'] ?? null,
            'inventory' => $payload['inventory'],
            'available_seats' => $payload['inventory'],
            'seat_map' => $payload['seat_map'] ?? null,
            'seating_configuration' => $payload['seating_configuration'] ?? null,
        ];

        $bookable = $this->bookableManager->createBookable($bookablePayload, $user);

        if ($request->boolean('publish', true)) {
            $bookable = $this->bookableManager->publish($bookable);
        }

        return response()->json($bookable->load(['safari', 'ticketTiers', 'media', 'primaryPayoutProfile']), 201);
    }

    public function getSafari(string $safariId): JsonResponse
    {
        $bookable = Bookable::query()
            ->where('type', 'sacco_safari')
            ->with(['safari.saccoRoute.route', 'ticketTiers', 'media', 'sacco'])
            ->find($safariId);

        if (!$bookable) {
            return response()->json(['message' => 'Safari not found'], 404);
        }

        return response()->json($bookable);
    }

    public function bookSafariSeat(Request $request, string $safariId): JsonResponse
    {
        return response()->json([
            'message' => 'Direct seat reservations are disabled. Please use the standard booking flow.',
        ], 410);
    }

    protected function generateSaccoId(string $saccoName): string
    {
        $prefix = strtoupper(substr($saccoName, 0, 2));
        if (strlen($prefix) < 2) {
            $prefix = str_pad($prefix, 2, 'X');
        }

        $lastSacco = Sacco::where('sacco_id', 'LIKE', $prefix . '%')
            ->orderBy('sacco_id', 'desc')
            ->first();

        if ($lastSacco) {
            $lastNumber = (int) substr($lastSacco->sacco_id, 2);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    protected function generateUniquePlaceholderEmail(string $slug): string
    {
        do {
            $email = sprintf('%s.%s@placeholder.local', $slug, Str::uuid());
        } while (Sacco::where('sacco_email', $email)->exists());

        return $email;
    }

    protected function generateUniquePlaceholderPhone(): string
    {
        do {
            $phone = '999' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        } while (Sacco::where('sacco_phone', $phone)->exists());

        return $phone;
    }

    protected function tierAllowsSafaris(Sacco $sacco): bool
    {
        $features = $sacco->tier?->features;

        if (is_string($features)) {
            $decoded = json_decode($features, true);
            $features = json_last_error() === JSON_ERROR_NONE ? $decoded : $features;
        }

        if (is_array($features)) {
            if (array_values($features) === $features) {
                return in_array('safaris', $features, true) || in_array('safari', $features, true);
            }

            return !empty($features['safaris']) || !empty($features['safari']);
        }

        return false;
    }

    private function profileUpdateRules(Sacco $sacco): array
    {
        return [
            'sacco_name' => 'sometimes|string|max:255',
            'sacco_phone' => 'sometimes|string|max:255',
            'sacco_email' => 'sometimes|email|max:255',
            'vehicle_type' => 'sometimes|string|max:255',
            'sacco_location' => 'sometimes|string|max:255',
            'till_number' => 'sometimes|nullable|string|max:20',
            'paybill_number' => 'sometimes|nullable|string|max:20',
            'sacco_logo' => 'sometimes|nullable|string|max:255',
            'sacco_website' => 'sometimes|nullable|url',
            'profile_headline' => 'sometimes|nullable|string|max:255',
            'profile_description' => 'sometimes|nullable|string',
            'share_slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('saccos', 'share_slug')->ignore($sacco->sacco_id, 'sacco_id'),
            ],
            'profile_contact_name' => 'sometimes|nullable|string|max:255',
            'profile_contact_phone' => 'sometimes|nullable|string|max:50',
            'profile_contact_email' => 'sometimes|nullable|email|max:255',
        ];
    }

    private function normalizeShareSlug(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $slug = Str::slug($value);

        return $slug !== '' ? $slug : null;
    }
}
