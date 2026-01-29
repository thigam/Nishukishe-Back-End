<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferEdge extends Model
{
    public $timestamps = false;
    protected $table = 'transfer_edges';
    protected $fillable = [
        'from_stop_id',
        'to_stop_id',
        'walk_time_seconds',
        'geometry',
    ];

    protected $casts = [
        'geometry' => 'array',
    ];
}
