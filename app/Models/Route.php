<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Route extends Model
{
    public $incrementing = false;
    public $timestamps = false;
    protected $primaryKey = 'route_id';
    protected $keyType = 'string';

    protected $fillable = [
        'route_id',
        'route_number',
        'route_start_stop',
        'route_end_stop',
    ];

    /**
     * All the saccos that serve this route.
     */
    public function saccos()
    {
        return $this->belongsToMany(
            Sacco::class,
            'sacco_routes',
            'route_id',
            'sacco_id'
        );
    }

    public function saccoRoutes()
    {
        return $this->hasMany(SaccoRoute::class, 'route_id', 'route_id');
    }
}

