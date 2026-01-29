<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'bookable_id',
        'type',
        'url',
        'title',
        'alt_text',
        'position',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function bookable(): BelongsTo
    {
        return $this->belongsTo(Bookable::class);
    }
}
