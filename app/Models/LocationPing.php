<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationPing extends Model
{
    public $timestamps = false; // We manage created_at manually via insert()

    protected $fillable = [
        'device_id', 'lat', 'lng',
        'accuracy_meters', 'speed_kmh', 'recorded_at', 'created_at',
    ];

    protected $casts = [
        'lat'         => 'float',
        'lng'         => 'float',
        'recorded_at' => 'datetime',
        'created_at'  => 'datetime',
    ];
}
