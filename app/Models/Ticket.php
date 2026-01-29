<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'bookable_id',
        'ticket_tier_id',
        'booking_id',
        'qr_code',
        'status',
        'passenger_name',
        'passenger_email',
        'passenger_metadata',
        'price_paid',
        'scanned_at',
        'scan_count',
        'seat_number',
    ];

    protected $casts = [
        'passenger_metadata' => 'array',
        'price_paid' => 'float',
        'scanned_at' => 'datetime',
        'scan_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $ticket): void {
            if (!$ticket->uuid) {
                $ticket->uuid = (string) Str::uuid();
            }

            if (!$ticket->qr_code) {
                $ticket->qr_code = Str::upper(Str::random(10));
            }
        });
    }

    public function bookable(): BelongsTo
    {
        return $this->belongsTo(Bookable::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function ticketTier(): BelongsTo
    {
        return $this->belongsTo(TicketTier::class);
    }

    public function markScanned(): void
    {
        $this->status = 'scanned';
        if ($this->scanned_at === null) {
            $this->scanned_at = now();
        }

        $this->scan_count = ((int) $this->scan_count) + 1;
        $this->save();
    }
}
