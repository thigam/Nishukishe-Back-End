<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialMetricSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'social_account_id',
        'collected_at',
        'followers',
        'post_count',
        'interaction_score',
        'interaction_score_change_pct',
        'followers_change_pct',
        'post_count_change_pct',
        'metrics_breakdown',
    ];

    protected $casts = [
        'collected_at' => 'datetime',
        'metrics_breakdown' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
