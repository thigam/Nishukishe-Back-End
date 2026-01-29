<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketHold extends Model
{
    protected $fillable = [
        'ticket_tier_id',
        'session_id',
        'quantity',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
