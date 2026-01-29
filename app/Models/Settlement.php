<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'bookable_id',
        'payout_profile_id',
        'total_amount',
        'fee_amount',
        'net_amount',
        'requested_amount',
        'status',
        'period_start',
        'period_end',
        'settled_at',
        'metadata',
        'requested_at',
        'requested_by',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'fee_amount' => 'float',
        'net_amount' => 'float',
        'requested_amount' => 'float',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'settled_at' => 'datetime',
        'requested_at' => 'datetime',
        'metadata' => 'array',
        'requested_by' => 'array',
    ];

    public function bookable(): BelongsTo
    {
        return $this->belongsTo(Bookable::class)->withTrashed();
    }

    public function payoutProfile(): BelongsTo
    {
        return $this->belongsTo(PayoutProfile::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
