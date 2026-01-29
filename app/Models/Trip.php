<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    protected $casts = [
        'stop_times' => 'array',
        'day_of_week' => 'array',
    ];

    protected $fillable = [
        'trip_id',
        'sacco_id',
        'route_id',
        'sacco_route_id',
        'trip_index',
        'stop_times',
        'day_of_week',
        'start_time',
    ];

    public function saccoRoute()
    {
        return $this->belongsTo(SaccoRoutes::class, 'sacco_route_id', 'sacco_route_id');
    }
}
