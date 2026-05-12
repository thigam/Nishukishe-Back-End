<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentNotification extends Model
{
    public $timestamps = false; // We use created_at, but not updated_at

    protected $fillable = [
        'incident_id',
        'user_id',
        'device_id',
        'created_at',
        'clicked_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'clicked_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
