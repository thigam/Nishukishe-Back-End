<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Incident extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'vehicle_id',
        'user_id',
        'type',
        'lat',
        'lng',
        'description',
        'is_verified',
        'reported_at',
        'upvotes',
        'downvotes',
        'start_time',
        'end_time',
        'path_coordinates',
        'incident_sub_type',
        'roads',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'is_verified' => 'boolean',
        'reported_at' => 'datetime',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'path_coordinates' => 'array',
        'roads' => 'array',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
