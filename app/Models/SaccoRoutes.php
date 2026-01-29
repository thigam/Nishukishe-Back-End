<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaccoRoutes extends Model
{
    public $incrementing = false;
    public $timestamps    = false;
    protected $primaryKey = 'sacco_route_id';
    protected $keyType    = 'string';

    protected $fillable = [
        'sacco_route_id',
        'route_id',
        'sacco_id',
        'stop_ids',
        'coordinates',
       // 'route_stop_times',
        'peak_fare',
        'off_peak_fare',
        'currency',
        'scheduled',
        'has_variations',
    ];

    protected $casts = [
        'coordinates'      => 'array',
        'stop_ids'         => 'array',
       // 'route_stop_times' => 'json',
        'peak_fare'       => 'float',
        'off_peak_fare'       => 'float',
        'scheduled'       => 'boolean',
        'has_variations'  => 'boolean',
    ];

    public static function generateSaccoRouteId(string $saccoId, string $routeId): string
    {
        $index = self::where('sacco_id', $saccoId)
            ->where('route_id', $routeId)
            ->count() + 1;

        return sprintf('%s_%s_%03d', $saccoId, $routeId, $index);
    }

    public function sacco()
    {
        return $this->belongsTo(Sacco::class, 'sacco_id', 'sacco_id');
    }

    public function stops()
    {
        return Stops::whereIn('stop_id', $this->stop_ids ?: [])->get();
    }

    public function stopTimes()
    {
        return $this->hasMany(StopTimes::class, 'sacco_route_id', 'sacco_route_id');
    }

    public function route()
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    public function variations()
    {
        return $this->hasMany(Variation::class, 'sacco_route_id', 'sacco_route_id');
    }
}
