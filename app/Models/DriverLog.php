<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User; // Assuming User model is in App\Models
use App\Models\Vehicle; // Assuming Vehicle model is in App\Models
use App\Models\SaccoRoute; // Assuming SaccoRoute model is in App\Models

class DriverLog extends Model
{
    protected $fillable = [
        'driver_id',
        'vehicle_id',
        'sacco_route_id',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function saccoRoute()
    {
        return $this->belongsTo(SaccoRoute::class, 'sacco_route_id', 'sacco_route_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }
}
