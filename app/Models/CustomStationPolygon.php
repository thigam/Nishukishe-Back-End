<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomStationPolygon extends Model
{
    protected $fillable = [
        'name',
        'polygon',
        'station_id',
    ];

    protected $casts = [
        'polygon' => 'array',
    ];
}
