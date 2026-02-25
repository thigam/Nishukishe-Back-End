<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Nishukishe Safari Ticket</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1f2937;
            font-size: 13px;
            line-height: 1.5;
            background-color: #ffffff;
            margin: 0;
            padding: 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #4f46e5;
            letter-spacing: -0.5px;
        }

        .reference-badge {
            display: inline-block;
            background-color: #f3f4f6;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            color: #4b5563;
            margin-top: 10px;
            font-family: monospace;
            font-size: 14px;
        }

        .summary-card {
            margin-bottom: 30px;
            padding: 20px;
            border-radius: 12px;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
        }

        .summary-grid {
            width: 100%;
        }

        .summary-grid td {
            padding: 8px 0;
            vertical-align: top;
        }

        .label {
            font-weight: 600;
            color: #6b7280;
            width: 140px;
        }

        .value {
            font-weight: 500;
            color: #111827;
        }

        .ticket-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 0;
            margin-bottom: 20px;
            page-break-inside: avoid;
        }

        .ticket-header {
            background-color: #f9fafb;
            padding: 12px 20px;
            border-bottom: 1px solid #e5e7eb;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
            font-weight: bold;
            color: #374151;
            font-size: 15px;
            display: flex;
            justify-content: space-between;
        }

        .ticket-body {
            padding: 20px;
        }

        .ticket-layout {
            width: 100%;
        }

        .ticket-layout td.info-cell {
            vertical-align: top;
            padding-right: 20px;
        }

        .ticket-layout td.qr-cell {
            vertical-align: middle;
            text-align: right;
            width: 140px;
        }

        .ticket-detail-row {
            margin-bottom: 10px;
        }

        .ticket-detail-row span.d-label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 2px;
        }

        .ticket-detail-row span.d-value {
            display: block;
            font-weight: 600;
            font-size: 14px;
        }

        .qr-wrapper {
            background: white;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            display: inline-block;
        }

        .qr-wrapper img {
            width: 120px;
            height: 120px;
            display: block;
        }

        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 20px;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>Nishukishe Safari Boarding Pass</h1>
        <div class="reference-badge">Booking Ref: {{ $booking->reference }}</div>
    </div>

    <div class="summary-card">
        <table class="summary-grid" cellspacing="0" cellpadding="0">
            <tr>
                <td class="label">Lead Passenger:</td>
                <td class="value">{{ $booking->customer_name }}</td>
            </tr>
            <tr>
                <td class="label">Experience:</td>
                <td class="value">{{ $booking->bookable?->title ?? 'Nishukishe Safari' }}</td>
            </tr>
            <tr>
                <td class="label">Travel Window:</td>
                <td class="value">
                    @if($booking->bookable?->starts_at)
                        {{ $booking->bookable->starts_at->format('l, d M Y \a\t H:i A') }}
                    @else
                        TBA
                    @endif
                </td>
            </tr>
            <tr>
                <td class="label">Total Tickets:</td>
                <td class="value">{{ $booking->quantity }}</td>
            </tr>
            <tr>
                <td class="label">Issued On:</td>
                <td class="value">{{ $generatedAt->format('d M Y H:i') }}</td>
            </tr>
        </table>
    </div>

    @foreach($booking->tickets as $ticket)
        <div class="ticket-card">
            <div class="ticket-header">
                Ticket #{{ $loop->iteration }} of {{ $booking->quantity }}
            </div>
            <div class="ticket-body">
                <table class="ticket-layout" cellspacing="0" cellpadding="0">
                    <tr>
                        <td class="info-cell">
                            <div class="ticket-detail-row">
                                <span class="d-label">Passenger Name</span>
                                <span class="d-value">{{ $ticket->passenger_name ?? $booking->customer_name }}</span>
                            </div>
                            <div class="ticket-detail-row">
                                <span class="d-label">Ticket Tier</span>
                                <span class="d-value">{{ $ticket->ticketTier?->name ?? 'Standard Admission' }}</span>
                            </div>
                            <div class="ticket-detail-row">
                                <span class="d-label">Seat / Reference</span>
                                <span class="d-value">{{ $ticket->seat_number ?: 'Unassigned' }} &bull;
                                    {{ substr($ticket->uuid, 0, 8) }}</span>
                            </div>
                        </td>
                        <td class="qr-cell">
                            <div class="qr-wrapper">
                                @if(isset($qrImages[$ticket->id]))
                                    <img src="data:image/svg+xml;base64,{{ $qrImages[$ticket->id] }}" alt="QR code">
                                @else
                                    <p style="text-align:center;font-size:10px;margin:0;">QR Unavailable</p>
                                @endif
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    @endforeach

    <div class="footer">
        Present this digital or printed pass to the operator at boarding. Each QR code is strictly valid for a single
        scan.<br>
        For assistance, please contact support@nishukishe.com.
    </div>
</body>

</html>