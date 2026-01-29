<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'download_token',
        'bookable_id',
        'user_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'quantity',
        'currency',
        'total_amount',
        'service_fee_amount',
        'net_amount',
        'status',
        'seat_number',
        'payment_status',
        'paid_at',
        'settlement_id',
        'metadata',
        'refund_status',
        'refund_amount',
        'refund_reason',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'service_fee_amount' => 'float',
        'net_amount' => 'float',
        'refund_amount' => 'float',
        'metadata' => 'array',
        'paid_at' => 'datetime',
    ];

    protected $hidden = [
        'download_token',
    ];

    protected $appends = [
        'ticket_download_url',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $booking): void {
            if (!$booking->reference) {
                $booking->reference = strtoupper(Str::random(12));
            }

            if (!$booking->download_token) {
                $booking->download_token = Str::random(40);
            }
        });
    }

    public function bookable(): BelongsTo
    {
        return $this->belongsTo(Bookable::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function markAsPaid(string $status = 'paid'): void
    {
        $this->payment_status = $status;
        $this->status = $status === 'paid' ? 'confirmed' : $this->status;
        $this->paid_at = now();
        $this->save();

        if ($this->status === 'confirmed') {
            $freshBooking = $this->fresh(['tickets', 'bookable.saccoRoute.route', 'bookable.sacco']);

            // Send Email
            \Illuminate\Support\Facades\Mail::to($this->customer_email)->send(new \App\Mail\TicketReceiptMail($freshBooking));

            // Send SMS
            try {
                $smsService = app(\App\Services\SmsService::class);
                if ($this->customer_phone) {
                    $ticket = $freshBooking->tickets->first(); // Send one SMS per booking for now, or loop? Usually one main SMS is enough.
                    // Let's send one SMS with the first ticket details + count
                    if ($ticket) {
                        $saccoName = $freshBooking->bookable->sacco->sacco_name ?? 'Nishukishe';
                        $route = $freshBooking->bookable->saccoRoute->route->name ?? 'Safari';
                        $time = $freshBooking->bookable->starts_at?->format('d M H:i') ?? '';
                        $ticketNumber = $ticket->ticket_number ?? $freshBooking->reference;

                        $smsService->sendTicketSms(
                            $this->customer_phone,
                            $ticketNumber,
                            $saccoName,
                            $route,
                            $time
                        );
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to send SMS for booking {$this->id}: " . $e->getMessage());
            }
        }
    }

    public function getTicketDownloadUrlAttribute(): ?string
    {
        if (!$this->download_token) {
            return null;
        }

        return route('bookings.tickets.download', [
            'booking' => $this->getKey(),
            'token' => $this->download_token,
        ]);
    }
}
