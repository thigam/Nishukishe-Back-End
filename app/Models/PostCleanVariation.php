<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostCleanVariation extends Model
{
    protected $fillable = [
        'pre_clean_id',
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
        return $this->belongsTo(PostCleanSaccoRoute::class, 'sacco_route_id', 'sacco_route_id');
    }

    public function preClean()
    {
        return $this->belongsTo(PreCleanVariation::class, 'pre_clean_id');
    }
}
