<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Variation extends Model
{
    public $incrementing = true;
    public $timestamps = false;
    protected $primaryKey = 'variation_id';

    protected $fillable = [
        'sacco_route_id',
        'coordinates',
        'stop_ids',
    ];

    protected $casts = [
        'coordinates' => 'array',
        'stop_ids'    => 'array',
    ];

    public function saccoRoute()
    {
        return $this->belongsTo(SaccoRoutes::class, 'sacco_route_id', 'sacco_route_id');
    }
}
