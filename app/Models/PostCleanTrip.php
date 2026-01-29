<?php

// app/Models/PostCleanTrip.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostCleanTrip extends Model
{
    protected $fillable = [
        'pre_clean_id',
        'route_id',
        'sacco_id',
        'sacco_route_id',
        'trip_times',
        'day_of_week',
    ];

    protected $casts = [
        'route_id'   => 'string',
        'sacco_route_id' => 'string',
        'trip_times' => 'array',
        'day_of_week' => 'array',
    ];

    public function saccoRoute()
    {
        return $this->belongsTo(PostCleanSaccoRoute::class, 'sacco_route_id', 'sacco_route_id');
    }
}
