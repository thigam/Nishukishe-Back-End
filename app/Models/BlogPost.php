<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class BlogPost extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'author_id',
        'slug',
        'title',
        'excerpt',
        'cover_image_url',
        'status',
        'published_at',
        'published_version_number',
        'approved_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BlogPostVersion::class);
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(BlogPostVersion::class)->latestOfMany('version_number');
    }

    public function publishedVersion(): HasOne
    {
        return $this->hasOne(BlogPostVersion::class)
            ->where('is_published', true)
            ->latestOfMany('version_number');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(BlogPostStatusEvent::class);
    }

    public function createVersion(array $attributes): BlogPostVersion
    {
        $nextVersion = ($this->versions()->max('version_number') ?? 0) + 1;

        return $this->versions()->create(array_merge($attributes, [
            'version_number' => $nextVersion,
        ]));
    }

    public function markPublishedVersion(BlogPostVersion $version): void
    {
        $this->versions()
            ->where('is_published', true)
            ->update(['is_published' => false]);

        $version->is_published = true;
        $version->save();

        $this->published_version_number = $version->version_number;
    }

    public static function generateUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        if (empty($base)) {
            $base = 'blog-post';
        }

        $slug = $base;
        $suffix = 1;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->whereIn('status', [self::STATUS_PUBLISHED, self::STATUS_ARCHIVED])
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
