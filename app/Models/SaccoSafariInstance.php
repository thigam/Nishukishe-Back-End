<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoSafariInstance extends Model
{
    use HasFactory;

    protected $fillable = [
        'bookable_id',
        'sacco_id',
        'sacco_route_id',
        'trip_id',
        'vehicle_id',
        'departure_time',
        'arrival_time',
        'inventory',
        'available_seats',
        'seat_map',
        'seating_configuration',
        'route_label',
        'metadata',
    ];

    protected $casts = [
        'departure_time' => 'datetime',
        'arrival_time' => 'datetime',
        'seat_map' => 'array',
        'seating_configuration' => 'array',
        'metadata' => 'array',
    ];

    public function bookable(): BelongsTo
    {
        return $this->belongsTo(Bookable::class);
    }

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class, 'sacco_id');
    }

    public function saccoRoute(): BelongsTo
    {
        return $this->belongsTo(SaccoRoutes::class, 'sacco_route_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
