<?php

namespace App\Services\Tickets;

use App\Models\Booking;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

class TicketPdfGenerator
{
    public function generate(Booking $booking): string
    {
        $booking->loadMissing(['bookable', 'tickets.ticketTier']);

        $qrImages = $this->generateQrImages($booking);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);

        $html = view('pdf.booking_tickets', [
            'booking' => $booking,
            'qrImages' => $qrImages,
            'generatedAt' => Carbon::now(),
        ])->render();

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function filename(Booking $booking): string
    {
        return sprintf('tembea-tickets-%s.pdf', $booking->reference);
    }

    protected function generateQrImages(Booking $booking): Collection
    {
        $renderer = new ImageRenderer(
            new RendererStyle(320),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);

        return $booking->tickets->mapWithKeys(function ($ticket) use ($writer) {
            try {
                $data = $writer->writeString((string) $ticket->qr_code);
            } catch (\Throwable $e) {
                throw new RuntimeException('Failed to generate QR code for ticket '.$ticket->id, 0, $e);
            }

            return [
                $ticket->id => base64_encode($data),
            ];
        });
    }
}
