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
        'origin_depot_id',
        'destination_depot_id',
        'current_depot_id',
        'sender_name',
        'sender_phone',
        'sender_email',
        'receiver_name',
        'receiver_phone',
        'receiver_email',
        'status',
        'delivery_code',
        'fee',
        'description',
    ];

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class, 'sacco_id', 'sacco_id');
    }

    public function originDepot(): BelongsTo
    {
        return $this->belongsTo(ParcelDepot::class, 'origin_depot_id');
    }

    public function destinationDepot(): BelongsTo
    {
        return $this->belongsTo(ParcelDepot::class, 'destination_depot_id');
    }

    public function currentDepot(): BelongsTo
    {
        return $this->belongsTo(ParcelDepot::class, 'current_depot_id');
    }
}
