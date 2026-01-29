<?php

namespace App\Http\Controllers\Bookings;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Tickets\TicketPdfGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketDownloadController extends Controller
{
    public function __construct(private readonly TicketPdfGenerator $generator)
    {
    }

    public function __invoke(Request $request, Booking $booking): Response|StreamedResponse
    {
        $token = (string) $request->query('token', '');

        if ($token === '' || ! hash_equals((string) $booking->download_token, $token)) {
            abort(403, 'Invalid download token.');
        }

        $pdf = $this->generator->generate($booking);
        $filename = $this->generator->filename($booking);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
