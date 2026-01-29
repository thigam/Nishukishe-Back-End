<x-mail::message>
# Asante for booking with Nishukishe!

We have received your order **{{ $booking->reference }}** for **{{ $booking->bookable->title }}**.

**Event date:** {{ optional($booking->bookable->starts_at)->format('d M Y H:i') ?? 'TBA' }}

**Tickets purchased:** {{ $booking->quantity }}

@foreach($booking->tickets as $ticket)
- **Ticket:** {{ $ticket->uuid }} | **Passenger:** {{ $ticket->passenger_name ?? $booking->customer_name }} | **QR:** {{ $ticket->qr_code }}
@endforeach

You can present any of the QR codes above at the boarding gate for quick verification.

We've attached a printable PDF copy of your tickets. You can also retrieve it any time using the link below:

@isset($downloadUrl)
<x-mail::button :url="$downloadUrl">
Download Tembea Tickets (PDF)
</x-mail::button>
@endisset

<x-mail::panel>
Amount paid: {{ number_format($booking->total_amount, 2) }} {{ $booking->currency }}
Service fee: {{ number_format($booking->service_fee_amount, 2) }} {{ $booking->currency }}
Net to operator: {{ number_format($booking->net_amount, 2) }} {{ $booking->currency }}
</x-mail::panel>

If you have any questions just reply to this email and our team will assist.

Thanks,<br>
{{ config('app.name', 'Nishukishe') }}
</x-mail::message>
