<!DOCTYPE html>
<html>

<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
            line-height: 1.6;
        }

        .wrapper {
            background-color: #f8fafc;
            padding: 40px 20px;
            width: 100%;
            box-sizing: border-box;
        }

        .content {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }

        .header {
            background-color: #4f46e5;
            padding: 40px 30px;
            text-align: center;
            color: #ffffff;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.025em;
        }

        .body-content {
            padding: 40px 30px;
        }

        .text-slate-500 {
            color: #64748b;
        }

        .text-slate-900 {
            color: #0f172a;
        }

        .font-bold {
            font-weight: 700;
        }

        .ticket-card {
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .ticket-detail {
            margin-bottom: 12px;
            font-size: 14px;
        }

        .ticket-label {
            color: #64748b;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            display: block;
            margin-bottom: 4px;
        }

        .ticket-value {
            color: #1e3a8a;
            font-weight: 600;
            font-size: 16px;
        }

        .passenger-list {
            margin-top: 24px;
        }

        .passenger-item {
            border-bottom: 1px solid #f1f5f9;
            padding: 12px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .passenger-item:last-child {
            border-bottom: none;
        }

        .btn-primary {
            display: inline-block;
            background-color: #4f46e5;
            color: #ffffff;
            padding: 14px 28px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            margin-top: 24px;
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.3);
        }

        .footer {
            padding: 30px;
            text-align: center;
            color: #94a3b8;
            font-size: 13px;
            background-color: #f8fafc;
        }
    </style>
</head>

<body>
    <div class="wrapper">
        <div class="content">
            <div class="header">
                <h1>Booking Confirmed!</h1>
                <p style="margin-top: 8px; color: #e0e7ff;">Thank you for choosing
                    {{ config('app.name', 'Nishukishe') }}</p>
            </div>
            <div class="body-content">
                <p class="text-slate-900" style="margin-bottom: 24px;">Hi {{ $booking->customer_name }},</p>
                <p class="text-slate-500" style="margin-bottom: 32px;">We have successfully received your order for
                    <strong>{{ $booking->bookable->title }}</strong>. Here are your booking details:</p>

                <div class="ticket-card">
                    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 16px;">
                        <tr>
                            <td width="50%" valign="top">
                                <span class="ticket-label">Order Reference</span>
                                <span class="ticket-value">{{ $booking->reference }}</span>
                            </td>
                            <td width="50%" valign="top">
                                <span class="ticket-label">Date & Time</span>
                                <span
                                    class="ticket-value">{{ optional($booking->bookable->starts_at)->format('D, d M Y h:i A') ?? 'TBA' }}</span>
                            </td>
                        </tr>
                    </table>
                    <table width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td width="50%" valign="top">
                                <span class="ticket-label">Total Paid</span>
                                <span class="ticket-value">{{ number_format($booking->total_amount, 2) }}
                                    {{ $booking->currency }}</span>
                            </td>
                            <td width="50%" valign="top">
                                <span class="ticket-label">Tickets</span>
                                <span class="ticket-value">{{ $booking->quantity }}</span>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="passenger-list">
                    <h3 class="text-slate-900"
                        style="font-size: 16px; margin-bottom: 8px; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;">
                        Passenger Details</h3>
                    @foreach($booking->tickets as $ticket)
                        <div class="passenger-item">
                            <div>
                                <span class="text-slate-900 font-bold"
                                    style="display: block;">{{ $ticket->passenger_name ?? $booking->customer_name }}</span>
                                <span class="text-slate-500" style="font-size: 13px;">Ticket:
                                    {{ substr($ticket->uuid, 0, 8) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>

                <p class="text-slate-500" style="margin-top: 32px; font-size: 14px;">Present your ticket details or the
                    attached PDF at the boarding gate for verification.</p>

                @isset($downloadUrl)
                    <div style="text-align: center;">
                        <a href="{{ $downloadUrl }}" class="btn-primary">Download Tickets (PDF)</a>
                    </div>
                @endisset
            </div>
            <div class="footer">
                <p style="margin: 0;">Need help? Reply to this email and our team will assist you.</p>
                <p style="margin: 8px 0 0 0;">&copy; {{ date('Y') }} {{ config('app.name', 'Nishukishe') }}. All rights
                    reserved.</p>
            </div>
        </div>
    </div>
</body>

</html>