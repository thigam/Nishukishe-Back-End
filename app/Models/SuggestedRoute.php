<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuggestedRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email',
        'start_stop_id',
        'end_stop_id',
        'sacco_id',
        'start_stop_manual',
        'end_stop_manual',
        'sacco_manual',
        'details',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function startStop(): BelongsTo
    {
        return $this->belongsTo(Stops::class, 'start_stop_id', 'stop_id');
    }

    public function endStop(): BelongsTo
    {
        return $this->belongsTo(Stops::class, 'end_stop_id', 'stop_id');
    }

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class, 'sacco_id', 'sacco_id');
    }
}
