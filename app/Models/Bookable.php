<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Bookable extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'organizer_id',
        'sacco_id',
        'type',
        'title',
        'slug',
        'subtitle',
        'description',
        'status',
        'currency',
        'service_fee_rate',
        'service_fee_flat',
        'terms_accepted_at',
        'published_at',
        'starts_at',
        'ends_at',
        'is_featured',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_featured' => 'boolean',
        'service_fee_rate' => 'float',
        'service_fee_flat' => 'float',
        'terms_accepted_at' => 'datetime',
        'published_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Bookable $bookable): void {
            if (! $bookable->uuid) {
                $bookable->uuid = (string) Str::uuid();
            }

            if (! $bookable->slug) {
                $bookable->slug = Str::slug($bookable->title.'-'.Str::random(6));
            }
        });
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class, 'sacco_id');
    }

    public function payoutProfiles(): HasMany
    {
        return $this->hasMany(PayoutProfile::class);
    }

    public function primaryPayoutProfile(): HasOne
    {
        return $this->hasOne(PayoutProfile::class)->where('is_primary', true);
    }

    public function media(): HasMany
    {
        return $this->hasMany(MediaAttachment::class)->orderBy('position');
    }

    public function ticketTiers(): HasMany
    {
        return $this->hasMany(TicketTier::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function safari(): HasOne
    {
        return $this->hasOne(SaccoSafariInstance::class);
    }

    public function tourEvent(): HasOne
    {
        return $this->hasOne(TourEvent::class);
    }
}
