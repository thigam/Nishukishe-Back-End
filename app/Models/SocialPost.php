<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SocialPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'social_account_id',
        'external_id',
        'permalink',
        'message',
        'published_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'published_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(SocialPostMetric::class);
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(SocialPostMetric::class)->latestOfMany('collected_at');
    }

    public function previousMetric(): HasOne
    {
        return $this->hasOne(SocialPostMetric::class)->ofMany('collected_at', 'max', function ($query) {
            $query->where('collected_at', '<', function ($subQuery) {
                $subQuery
                    ->select('collected_at')
                    ->from('social_post_metrics')
                    ->whereColumn('social_post_metrics.social_post_id', 'social_posts.id')
                    ->orderByDesc('collected_at')
                    ->limit(1);
            });
        });
    }
}
