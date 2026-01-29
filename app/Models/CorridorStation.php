<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorridorStation extends Model
{
    protected $table = 'corr_stations';
    protected $primaryKey = 'station_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'station_id',
        'lat',
        'lng',
        'l1_cell',
        'l0_cell',
        'route_degree'
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'route_degree' => 'integer',
    ];

    public function members()
    {
        return $this->hasMany(CorridorStationMember::class, 'station_id', 'station_id');
    }
}
