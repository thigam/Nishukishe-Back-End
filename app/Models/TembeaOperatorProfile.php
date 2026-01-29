<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Comment;

class TembeaOperatorProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_name',
        'slug',
        'contact_name',
        'contact_email',
        'contact_phone',
        'public_email',
        'public_phone',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (TembeaOperatorProfile $profile): void {
            if (! $profile->slug) {
                $profile->slug = static::generateUniqueSlug($profile->company_name);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    protected static function generateUniqueSlug(?string $companyName): string
    {
        $base = \Illuminate\Support\Str::slug((string) ($companyName ?? 'tembea-operator'));

        if ($base === '') {
            $base = 'tembea-operator';
        }

        $slug = $base;
        $suffix = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
