<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    protected $fillable = [
        'user_id', 'device_id', 'platform', 'token', 'token_type', 'is_active', 'sent_onboarding_tips',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sent_onboarding_tips' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
