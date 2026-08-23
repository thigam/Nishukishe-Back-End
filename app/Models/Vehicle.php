<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $fillable = [
        'owner_id',
        'sacco_id',
        'registration_number',
        'hardware_device_id',
        'driver_id',
        'route_id',
        'share_location_with_sacco',
    ];

    protected $casts = [
        'share_location_with_sacco' => 'boolean',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function ownerProfile()
    {
        return $this->hasOne(VehicleOwnerProfile::class, 'user_id', 'owner_id');
    }

    public function route()
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }
}
