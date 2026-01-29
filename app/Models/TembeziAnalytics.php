<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TembeziAnalytics extends Model
{
    use HasFactory;

    protected $fillable = [
        'tembezi_id',
        'event_type',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function tembezi(): BelongsTo
    {
        return $this->belongsTo(TourEvent::class, 'tembezi_id');
    }
}
