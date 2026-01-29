<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'metric_type',
        'value',
        'recorded_at',
        'post_identifier',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'recorded_at' => 'datetime',
        'value' => 'float',
    ];
}
