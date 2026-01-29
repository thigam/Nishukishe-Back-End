<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Route;
use App\Models\SaccoTier;
use App\Models\SaccoStage;
use App\Models\Comment;
use App\Models\SaccoManager;

class Sacco extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    /**
     * no timestamps
     * @var bool
     */
    public $timestamps = false;
    protected $primaryKey = 'sacco_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'sacco_id',
        'sacco_name',
        'vehicle_type',
        'join_date',
        'sacco_logo',
        'sacco_location',
        'sacco_phone',
        'sacco_email',
        'sacco_website',
        'sacco_routes',
        'registration_number',
        'is_approved',
        'tier_id',
        'till_number',
        'paybill_number',
        'profile_headline',
        'profile_description',
        'share_slug',
        'profile_contact_name',
        'profile_contact_phone',
        'profile_contact_email',
    ];

    protected $casts = [
        'sacco_routes' => 'json',
        'is_approved' => 'boolean',
        'tier_id' => 'integer',
    ];

    public function routes()
    {
        return $this->belongsToMany(Route::class, 'sacco_routes', 'sacco_id', 'route_id');
    }

    public function tier()
    {
        return $this->belongsTo(SaccoTier::class, 'tier_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(SaccoStage::class, 'sacco_id', 'sacco_id');
    }

    public function managers(): HasMany
    {
        return $this->hasMany(SaccoManager::class, 'sacco_id', 'sacco_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class, 'sacco_id', 'sacco_id');
    }

    public function hasParcelFeature(): bool
    {
        // Assuming 'Pro' and 'Premium' tiers have IDs or names we can check.
        // For now, let's check tier name via relationship if loaded, or just return true for testing if tier logic is complex.
        // Better: Check if tier name is in allowed list.
        if ($this->relationLoaded('tier')) {
            return in_array(strtolower($this->tier->name), ['pro', 'premium']);
        }
        // Fallback or load tier
        $tier = $this->tier;
        return $tier && in_array(strtolower($tier->name), ['pro', 'premium']);
    }

}
