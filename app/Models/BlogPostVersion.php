<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogPostVersion extends Model
{
    protected $fillable = [
        'blog_post_id',
        'version_number',
        'title',
        'content',
        'editor_notes',
        'created_by',
        'is_published',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
