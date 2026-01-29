<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Bookings\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RefundController extends Controller
{
    public function __construct(private readonly BookingService $bookingService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::whereNotNull('refund_status')
            ->with(['bookable', 'user', 'payments'])
            ->latest()
            ->paginate(20);

        return response()->json($bookings);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => ['required', 'exists:bookings,id'],
            'reason' => ['required', 'string', 'min:5'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $booking = Booking::findOrFail($request->booking_id);

        try {
            $this->bookingService->requestRefund($booking, $request->reason);
            return response()->json(['message' => 'Refund requested successfully.', 'booking' => $booking]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, Booking $booking): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'approved' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $this->bookingService->processRefund($booking, $request->approved);
            return response()->json(['message' => 'Refund processed successfully.', 'booking' => $booking]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
