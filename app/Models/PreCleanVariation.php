<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreCleanVariation extends Model
{
    protected $fillable = [
        'sacco_route_id',
        'coordinates',
        'stop_ids',
        'status',
    ];

    protected $casts = [
        'coordinates' => 'array',
        'stop_ids' => 'array',
    ];

    public function saccoRoute()
    {
        return $this->belongsTo(PreCleanSaccoRoute::class, 'sacco_route_id', 'sacco_route_id');
    }
}
