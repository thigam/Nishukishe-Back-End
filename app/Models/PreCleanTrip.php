<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
// app/Models/PreCleanTrip.php

class PreCleanTrip extends Model
{
    protected $fillable = [
        'sacco_route_id',
        'stop_times',
        'day_of_week',
    ];

    protected $casts = [
        'sacco_route_id' => 'string',
        'stop_times' => 'array',
        'day_of_week' => 'array',
    ];

    public function saccoRoute()
    {
        return $this->belongsTo(PreCleanSaccoRoute::class, 'sacco_route_id', 'sacco_route_id');
    }
}

