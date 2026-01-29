<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteVerification extends Model
{
    protected $fillable = [
        'route_id',
        'sacco_id',
        'sacco_route_ids',
        'verified_by',
        'verified_role',
        'notes',
        'verified_at',
    ];

    protected $casts = [
        'sacco_route_ids' => 'array',
        'verified_at' => 'datetime',
    ];
}
