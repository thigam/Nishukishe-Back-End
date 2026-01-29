<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreCleanSaccoRoute extends Model
{
    protected $table = 'pre_clean_sacco_routes';

    protected $fillable = [
        'sacco_id',
        'route_id',          // base id like 10200010811
        'sacco_route_id',    // composite like BS0001_10200010811_001
        'route_number',
        'route_start_stop',
        'route_end_stop',
        'coordinates',
        'stop_ids',
        'route_stop_times',
        'peak_fare',
        'off_peak_fare',
        'currency',
        'notes',
        'county_id',
        'mode',
        'waiting_time',
        'status',
        'direction_index',
    ];

    protected $casts = [
        'coordinates'      => 'array',
        'stop_ids'         => 'array',
        'route_stop_times' => 'array',
        'peak_fare'       => 'decimal:2',
        'off_peak_fare'       => 'decimal:2',
        'direction_index'  => 'integer',
    ];


    public function variations()
    {
        return $this->hasMany(PreCleanVariation::class, 'sacco_route_id', 'sacco_route_id');
    }
}
