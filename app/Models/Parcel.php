<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Parcel extends Model
{
    use HasFactory;

    protected $fillable = [
        'tracking_number',
        'sacco_id',
        'sender_name',
        'sender_phone',
        'sender_email',
        'receiver_name',
        'receiver_phone',
        'receiver_email',
        'status',
        'fee',
        'description',
    ];

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class);
    }
}
