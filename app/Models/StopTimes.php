<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StopTimes extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'trip_id',
        'sacco_route_id',
        'route_id',
        'arrival_time',
        'depature_time',
        'stop_id_in_sequence',
        'off_peak_time',
        'peak_time',
    ];

    protected $casts = [
        'stop_id_in_sequence' => 'json',
    ];

    public function stop()
    {
        return $this->belongsTo(Stops::class, 'stop_id_in_sequence', 'stop_id');
    }

    public function route()
    {
        return $this->belongsTo(SaccoRoutes::class, 'sacco_route_id', 'sacco_route_id');
    }
}
