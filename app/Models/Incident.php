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
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'is_verified' => 'boolean',
        'reported_at' => 'datetime',
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
