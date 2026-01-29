<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $fillable = [
        'owner_id',
        'sacco_id',
        'registration_number',
        'driver_id',
        'route_id',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function route()
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }
}
