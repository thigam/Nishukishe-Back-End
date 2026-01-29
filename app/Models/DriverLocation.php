<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DriverLocation extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'driver_id',
        'vehicle_id',
        'lat',
        'lng',
        'recorded_at',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'recorded_at' => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
