<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingNotification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'device_token_id', 'tip_key', 'created_at', 'clicked_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'clicked_at' => 'datetime',
    ];

    public function deviceToken(): BelongsTo
    {
        return $this->belongsTo(DeviceToken::class);
    }
}
