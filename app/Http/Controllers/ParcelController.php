<?php

namespace App\Http\Controllers;

use App\Models\Parcel;
use App\Models\Sacco;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ParcelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // Assuming user is a sacco manager and has a sacco_id or linked sacco
        // For now, let's assume we pass sacco_id or filter by user's sacco
        // This part depends on how Sacco Managers are authenticated.
        // I'll assume standard filtering for now.

        $query = Parcel::query();

        if ($request->has('sacco_id')) {
            $query->where('sacco_id', $request->sacco_id);
        }

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sacco_id' => ['required', 'exists:saccos,id'],
            'sender_name' => ['required', 'string'],
            'sender_phone' => ['required', 'string'],
            'sender_email' => ['nullable', 'email'],
            'receiver_name' => ['required', 'string'],
            'receiver_phone' => ['required', 'string'],
            'receiver_email' => ['nullable', 'email'],
            'fee' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],
        ]);

        // Generate Tracking Number
        $trackingNumber = 'PKG-' . strtoupper(Str::random(8));

        $parcel = Parcel::create([
            ...$validated,
            'tracking_number' => $trackingNumber,
            'status' => 'registered',
        ]);

        // TODO: Send SMS/Email to Sender & Receiver
        $this->sendNotifications($parcel, 'registered');

        return response()->json($parcel, 201);
    }

    public function updateStatus(Request $request, Parcel $parcel): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['registered', 'in_transit', 'received', 'delivered'])],
        ]);

        $parcel->update(['status' => $validated['status']]);

        // TODO: Send SMS/Email on status change
        $this->sendNotifications($parcel, $validated['status']);

        return response()->json($parcel);
    }

    private function sendNotifications(Parcel $parcel, string $status)
    {
        // Placeholder for notification logic
        // We would use the Notification service here
        // e.g., Notification::send([$parcel->sender_email, $parcel->receiver_email], new ParcelStatusUpdated($parcel));
        // For now, we just log it.
        \Illuminate\Support\Facades\Log::info("Parcel {$parcel->tracking_number} status updated to {$status}. Notifications sent to {$parcel->sender_phone} and {$parcel->receiver_phone}");
    }
}
