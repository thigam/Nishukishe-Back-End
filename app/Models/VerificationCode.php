<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationCode extends Model
{
    protected $fillable = [
        'parcel_id',
        'driver_code',
        'receiver_code',
        'driver_mismatches',
        'receiver_mismatches',
    ];

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }
}
