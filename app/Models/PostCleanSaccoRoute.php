<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostCleanSaccoRoute extends Model
{
    protected $table = 'post_clean_sacco_routes';

    protected $fillable = [
        'pre_clean_id',
        'route_id',          // base id like 10200010811
        'sacco_route_id',    // composite like BS0001_10200010811_001
        'sacco_id',
        'route_number',
        'route_start_stop',
        'route_end_stop',
        'coordinates',
        'stop_ids',
        'peak_fare',
        'off_peak_fare',
        'currency',
        'county_id',
        'mode',
        'waiting_time',
        'direction_index',
    ];

    protected $casts = [
        'coordinates'      => 'array',
        'stop_ids'         => 'array',
        'peak_fare'       => 'decimal:2',
        'off_peak_fare'       => 'decimal:2',
        'direction_index'  => 'integer',
    ];

    public function variations()
    {
        return $this->hasMany(PostCleanVariation::class, 'sacco_route_id', 'sacco_route_id');
    }
}
