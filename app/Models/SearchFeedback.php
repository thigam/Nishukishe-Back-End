<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchFeedback extends Model
{
    use HasFactory;

    protected $table = 'search_feedback';

    protected $fillable = [
        'user_start',
        'user_end',
        'sacco_route_id',
        'sacco_id',
        'grade',
        'ip_address',
        'session_id',
    ];

    public function saccoRoute(): BelongsTo
    {
        return $this->belongsTo(SaccoRoute::class);
    }

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class);
    }
}
