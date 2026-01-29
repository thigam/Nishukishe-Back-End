<?php

namespace App\Http\Controllers\Bookings;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TicketVerificationController extends Controller
{
    public function lookup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'qr_code' => ['required', 'string'],
        ]);
        $validator->validate();

        $ticket = Ticket::with(['booking.bookable'])
            ->where('qr_code', $request->string('qr_code'))
            ->firstOrFail();

        $this->authorizeTicket($ticket);

        return response()->json($ticket);
    }

    public function markScanned(Ticket $ticket): JsonResponse
    {
        $ticket->loadMissing('booking.bookable');
        $this->authorizeTicket($ticket);
        $ticket->markScanned();

        return response()->json($ticket);
    }

    protected function authorizeTicket(Ticket $ticket): void
    {
        if ($ticket->booking?->bookable?->organizer_id !== Auth::id()) {
            throw new AccessDeniedHttpException('You are not allowed to view this ticket.');
        }
    }
}
