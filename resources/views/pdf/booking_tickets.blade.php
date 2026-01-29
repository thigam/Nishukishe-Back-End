<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Tembea Tickets</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; }
        .header { text-align: center; margin-bottom: 16px; }
        .header h1 { margin: 0; font-size: 20px; }
        .summary { margin-bottom: 20px; border: 1px solid #cbd5f5; padding: 12px; border-radius: 8px; background: #f8fafc; }
        .summary dt { font-weight: bold; }
        .summary dd { margin: 0 0 8px 0; }
        .tickets { display: flex; flex-direction: column; gap: 14px; }
        .ticket { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; display: flex; }
        .ticket-info { flex: 1; }
        .ticket-info h2 { margin: 0 0 6px 0; font-size: 16px; }
        .ticket-info p { margin: 2px 0; }
        .ticket-qr { width: 140px; text-align: center; display: flex; align-items: center; justify-content: center; }
        .ticket-qr img { width: 120px; height: 120px; }
        .footer { margin-top: 30px; font-size: 10px; color: #475569; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Tembea Boarding Pass</h1>
        <p>Booking reference: <strong>{{ $booking->reference }}</strong></p>
    </div>

    <div class="summary">
        <dl>
            <dd><strong>Lead passenger:</strong> {{ $booking->customer_name }}</dd>
            <dd><strong>Experience:</strong> {{ $booking->bookable?->title }}</dd>
            <dd><strong>Travel window:</strong>
                @if($booking->bookable?->starts_at)
                    {{ $booking->bookable->starts_at->format('d M Y H:i') }}
                @else
                    TBA
                @endif
            </dd>
            <dd><strong>Total tickets:</strong> {{ $booking->quantity }}</dd>
            <dd><strong>Generated:</strong> {{ $generatedAt->format('d M Y H:i') }}</dd>
        </dl>
    </div>

    <div class="tickets">
        @foreach($booking->tickets as $ticket)
            <div class="ticket">
                <div class="ticket-info">
                    <h2>Ticket {{ $loop->iteration }}</h2>
                    <p><strong>Ticket ID:</strong> {{ $ticket->uuid }}</p>
                    <p><strong>Passenger:</strong> {{ $ticket->passenger_name ?? $booking->customer_name }}</p>
                    <p><strong>Tier:</strong> {{ $ticket->ticketTier?->name ?? 'General Admission' }}</p>
                    <p><strong>QR Code:</strong> {{ $ticket->qr_code }}</p>
                    <p><strong>Status:</strong> {{ ucfirst($ticket->status ?? 'valid') }}</p>
                </div>
                <div class="ticket-qr">
                    @if(isset($qrImages[$ticket->id]))
                        <img src="data:image/svg+xml;base64,{{ $qrImages[$ticket->id] }}" alt="QR code for ticket {{ $ticket->uuid }}">
                    @else
                        <p>QR unavailable</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="footer">
        Present this ticket at the Tembea check-in desk. Each QR code is valid for a single entry. For help contact support@nishukishe.com.
    </div>
</body>
</html>
