<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'bookable_id',
        'payout_type',
        'is_primary',
        'phone_number',
        'till_number',
        'paybill_number',
        'account_name',
        'bank_name',
        'bank_branch',
        'bank_account_number',
        'metadata',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'metadata' => 'array',
    ];

    public function bookable(): BelongsTo
    {
        return $this->belongsTo(Bookable::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }
}
