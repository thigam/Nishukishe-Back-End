<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'bookable_id',
        'name',
        'description',
        'currency',
        'price',
        'service_fee_rate',
        'service_fee_flat',
        'total_quantity',
        'remaining_quantity',
        'min_per_order',
        'max_per_order',
        'sales_start',
        'sales_end',
        'metadata',
    ];

    protected $casts = [
        'price' => 'float',
        'service_fee_rate' => 'float',
        'service_fee_flat' => 'float',
        'sales_start' => 'datetime',
        'sales_end' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tier): void {
            if ($tier->remaining_quantity === null) {
                $tier->remaining_quantity = $tier->total_quantity;
            }
        });
    }

    public function bookable(): BelongsTo
    {
        return $this->belongsTo(Bookable::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function reserve(int $quantity): void
    {
        if ($this->remaining_quantity < $quantity) {
            throw new \RuntimeException('Not enough tickets available for tier '.$this->name);
        }

        $this->remaining_quantity -= $quantity;
        $this->save();
    }
}
