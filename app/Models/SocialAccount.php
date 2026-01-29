<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'external_id',
        'display_name',
        'username',
        'profile_url',
        'avatar_url',
        'follower_count',
        'last_synced_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function snapshots(): HasMany
    {
        return $this->hasMany(SocialMetricSnapshot::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(SocialMetricSnapshot::class)->latestOfMany('collected_at');
    }

    public function previousSnapshot(): HasOne
    {
        return $this->hasOne(SocialMetricSnapshot::class)->ofMany('collected_at', 'max', function ($query) {
            $query->where('collected_at', '<', function ($subQuery) {
                $subQuery
                    ->select('collected_at')
                    ->from('social_metric_snapshots')
                    ->whereColumn('social_metric_snapshots.social_account_id', 'social_accounts.id')
                    ->orderByDesc('collected_at')
                    ->limit(1);
            });
        });
    }
}
