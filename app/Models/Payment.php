<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'booking_id',
        'provider',
        'provider_reference',
        'order_reference',
        'payment_reference',
        'channel',
        'equity_account_number',
        'payment_link',
        'receipt_number',
        'status',
        'amount',
        'fee_amount',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'fee_amount' => 'float',
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
