<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorridorStationMember extends Model
{
    protected $table = 'corr_station_members';

    protected $fillable = [
        'station_id',
        'stop_id'
    ];

    public function station()
    {
        return $this->belongsTo(CorridorStation::class, 'station_id', 'station_id');
    }

    public function stop()
    {
        return $this->belongsTo(Stops::class, 'stop_id', 'stop_id');
    }
}
