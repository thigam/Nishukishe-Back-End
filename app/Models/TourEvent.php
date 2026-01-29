<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Comment;

class TourEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'bookable_id',
        'destination',
        'meeting_point',
        'categories',
        'duration_label',
        'path_geojson',
        'stops',
        'marketing_copy',
        'highlights',
        'metadata',
        'checkout_type',
        'contact_info',
    ];

    protected $casts = [
        'destination' => 'array',
        'meeting_point' => 'array',
        'categories' => 'array',
        'path_geojson' => 'array',
        'stops' => 'array',
        'highlights' => 'array',
        'metadata' => 'array',
        'contact_info' => 'array',
    ];

    public function bookable(): BelongsTo
    {
        return $this->belongsTo(Bookable::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
