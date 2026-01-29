<?php

namespace App\Models;

use App\Models\Concerns\HasSaccoRouteIds;
use Illuminate\Database\Eloquent\Model;

class PreCleanStop extends Model
{
    use HasSaccoRouteIds;

    protected $fillable = [
        'sacco_route_ids',
        'stop_name',
        'stop_lat',
        'stop_long',
        'county_id',
        'direction_id',
        'status',
    ];

    protected $casts = [
        'sacco_route_ids' => 'array',
        'stop_lat'        => 'float',
        'stop_long'       => 'float',
    ];
}
