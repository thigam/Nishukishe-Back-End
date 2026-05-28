<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportedWrongInfo extends Model
{
    use HasFactory;

    protected $table = 'reported_wrong_info';

    protected $fillable = [
        'user_id',
        'search_start',
        'search_end',
        'legs',
        'selected_legs',
        'error_options',
        'details',
        'status',
    ];

    protected $casts = [
        'legs' => 'array',
        'selected_legs' => 'array',
        'error_options' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
