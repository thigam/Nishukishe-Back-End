<?php

namespace App\Http\Controllers;

use App\Models\Parcel;
use App\Models\ParcelEvent;
use App\Models\ParcelDepot;
use App\Models\SaccoManager;
use App\Models\UserPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Services\SmsService;

class ParcelController extends Controller
{
    private function hasSpecificPermission($user, $saccoId, $permission): bool
    {
        $isManager = SaccoManager::where('user_id', $user->id)->where('sacco_id', $saccoId)->exists();
        if ($isManager)
            return true;

        return UserPermission::where('user_id', $user->id)
            ->where('permission', $permission)
            ->exists();
    }

    private function agentAllowedAtDepot($user, $saccoId, int $depotId): bool
    {
        $isManager = SaccoManager::where('user_id', $user->id)->where('sacco_id', $saccoId)->exists();
        if ($isManager)
            return true;
        return DB::table('agent_depots')
            ->where('user_id', $user->id)
            ->where('depot_id', $depotId)
            ->exists();
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['sacco_id' => 'required|exists:saccos,sacco_id']);

        $query = Parcel::with(['originDepot', 'destinationDepot', 'currentDepot'])
            ->where('sacco_id', $request->sacco_id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('tracking_number', 'like', "%{$search}%")
                    ->orWhere('sender_name', 'like', "%{$search}%")
                    ->orWhere('receiver_name', 'like', "%{$search}%");
            });
        }

        return response()->json($query->latest()->paginate(20));
    }

    // RECEIVE: Register a new parcel at a depot
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sacco_id' => ['required', 'exists:saccos,sacco_id'],
            'origin_depot_id' => ['required', 'exists:parcel_depots,id'],
            'destination_depot_id' => ['required', 'exists:parcel_depots,id'],
            'sender_name' => ['required', 'string'],
            'sender_phone' => ['required', 'string'],
            'sender_email' => ['nullable', 'email'],
            'receiver_name' => ['required', 'string'],
            'receiver_phone' => ['required', 'string'],
            'receiver_email' => ['nullable', 'email'],
            'fee' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],
        ]);

        if (!$this->hasSpecificPermission($request->user(), $request->sacco_id, 'parcel_receive')) {
            return response()->json(['message' => 'Unauthorized to receive parcels.'], 403);
        }

        if (!$this->agentAllowedAtDepot($request->user(), $request->sacco_id, $validated['origin_depot_id'])) {
            return response()->json(['message' => 'You are not assigned to the origin depot.'], 403);
        }

        $trackingNumber = 'PKG-' . strtoupper(Str::random(8));
        $deliveryCode = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);

        $parcel = Parcel::create([
            ...$validated,
            'tracking_number' => $trackingNumber,
            'delivery_code' => $deliveryCode,
            'current_depot_id' => $validated['origin_depot_id'],
            'status' => 'registered',
        ]);

        ParcelEvent::create([
            'parcel_id' => $parcel->id,
            'user_id' => $request->user()->id,
            'action' => 'registered',
            'depot_id' => $validated['origin_depot_id'],
        ]);

        $this->sendNotifications($parcel, 'registered');

        return response()->json($parcel->load(['originDepot', 'destinationDepot']), 201);
    }

    // DISPATCH: Load/offload from a depot into a vehicle or new depot
    public function updateStatus(Request $request, Parcel $parcel): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['in_transit', 'received'])],
            'depot_id' => ['nullable', 'exists:parcel_depots,id'],
            'vehicle_registration' => ['nullable', 'string'],
        ]);

        if (!$this->hasSpecificPermission($request->user(), $parcel->sacco_id, 'parcel_dispatch')) {
            return response()->json(['message' => 'Unauthorized to dispatch parcels.'], 403);
        }

        if ($parcel->current_depot_id && !$this->agentAllowedAtDepot($request->user(), $parcel->sacco_id, $parcel->current_depot_id)) {
            return response()->json(['message' => 'You are not assigned to the current depot.'], 403);
        }

        $updates = ['status' => $validated['status']];
        if ($validated['status'] === 'received' && isset($validated['depot_id'])) {
            $updates['current_depot_id'] = $validated['depot_id'];
        } elseif ($validated['status'] === 'in_transit') {
            $updates['current_depot_id'] = null;
        }

        $parcel->update($updates);

        ParcelEvent::create([
            'parcel_id' => $parcel->id,
            'user_id' => $request->user()->id,
            'action' => $validated['status'],
            'depot_id' => $validated['depot_id'] ?? null,
            'vehicle_registration' => $validated['vehicle_registration'] ?? null,
        ]);

        if ($validated['status'] === 'received') {
            $this->sendNotifications($parcel, 'destination_arrival_otp');
        } else {
            $this->sendNotifications($parcel, $validated['status']);
        }

        return response()->json($parcel->fresh(['originDepot', 'destinationDepot', 'currentDepot']));
    }

    // DELIVER: Verify OTP and hand off to customer
    public function deliver(Request $request, Parcel $parcel): JsonResponse
    {
        $validated = $request->validate([
            'otp' => ['required', 'string'],
        ]);

        if (!$this->hasSpecificPermission($request->user(), $parcel->sacco_id, 'parcel_deliver')) {
            return response()->json(['message' => 'Unauthorized to deliver parcels.'], 403);
        }

        if ($parcel->status === 'delivered') {
            return response()->json(['message' => 'Parcel is already delivered.'], 400);
        }

        if ($parcel->delivery_code !== $validated['otp']) {
            return response()->json(['message' => 'Invalid delivery code (OTP).'], 422);
        }

        $parcel->update(['status' => 'delivered']);

        ParcelEvent::create([
            'parcel_id' => $parcel->id,
            'user_id' => $request->user()->id,
            'action' => 'delivered',
            'depot_id' => $parcel->current_depot_id,
        ]);

        $this->sendNotifications($parcel, 'delivered');

        return response()->json(['message' => 'Parcel confirmed delivered!']);
    }

    private function sendNotifications(Parcel $parcel, string $trigger)
    {
        $smsService = new SmsService();

        if ($trigger === 'registered') {
            $msgSender = "Your parcel {$parcel->tracking_number} has been registered and will be dispatched soon.";
            $msgReceiver = "You have a parcel ({$parcel->tracking_number}) coming your way from {$parcel->sender_name}. We will notify you when it arrives.";
            $smsService->send($parcel->sender_phone, $msgSender);
            $smsService->send($parcel->receiver_phone, $msgReceiver);
        } elseif ($trigger === 'destination_arrival_otp') {
            $msg = "Your parcel {$parcel->tracking_number} has arrived! Your delivery code is {$parcel->delivery_code}. Please present this code to collect your parcel.";
            $smsService->send($parcel->receiver_phone, $msg);
        } elseif ($trigger === 'delivered') {
            $msg = "Your parcel {$parcel->tracking_number} has been safely picked up by {$parcel->receiver_name}. Thank you for using our service!";
            $smsService->send($parcel->sender_phone, $msg);
        }
    }
}
