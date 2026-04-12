<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentVote extends Model
{
    protected $fillable = [
        'user_id',
        'incident_id',
        'type'
    ];
}
