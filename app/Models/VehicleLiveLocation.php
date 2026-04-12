<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VehicleLiveLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'sacco_route_id',
        'lat',
        'lng',
        'heading',
        'speed_kmh',
        'location_source',
        'is_active',
        'is_full',
        'recorded_at',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'heading' => 'integer',
        'speed_kmh' => 'float',
        'is_active' => 'boolean',
        'is_full' => 'boolean',
        'recorded_at' => 'datetime',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function saccoRoute()
    {
        return $this->belongsTo(SaccoRoute::class, 'sacco_route_id', 'sacco_route_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
