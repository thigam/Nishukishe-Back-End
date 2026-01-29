<?php

namespace App\Mail;

use App\Models\Booking;
use App\Services\Tickets\TicketPdfGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class TicketReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Booking $booking)
    {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Nishukishe Ticket Receipt',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $booking = $this->resolvedBooking();

        return new Content(
            markdown: 'emails.ticket_receipt',
            with: [
                'booking' => $booking,
                'downloadUrl' => $booking->ticket_download_url,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $booking = $this->resolvedBooking();

        /** @var TicketPdfGenerator $generator */
        $generator = app(TicketPdfGenerator::class);
        $pdf = $generator->generate($booking);

        return [
            Attachment::fromData(fn () => $pdf, $generator->filename($booking))
                ->withMime('application/pdf'),
        ];
    }

    protected function resolvedBooking(): Booking
    {
        if (! isset($this->booking->bookable) || ! $this->booking->relationLoaded('tickets')) {
            $this->booking->loadMissing(['bookable', 'tickets.ticketTier']);
        }

        return $this->booking;
    }
}
