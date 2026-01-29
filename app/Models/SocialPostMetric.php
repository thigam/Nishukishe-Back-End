<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPostMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'social_post_id',
        'collected_at',
        'likes',
        'comments',
        'shares',
        'views',
        'saves',
        'replies',
        'clicks',
        'interaction_score',
        'interaction_score_change_pct',
        'metrics_breakdown',
    ];

    protected $casts = [
        'collected_at' => 'datetime',
        'metrics_breakdown' => 'array',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }
}
