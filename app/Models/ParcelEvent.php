<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class ParcelEvent extends Model
{
    protected $fillable = [
        'parcel_id',
        'user_id',
        'action',
        'location',
    ];

    protected static function booted(): void
    {
        static::creating(function (ParcelEvent $event) {
            if (!$event->user_id && Auth::check()) {
                $event->user_id = Auth::id();
            }
        });
    }

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
