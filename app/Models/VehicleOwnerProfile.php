<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VehicleOwnerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'national_id',
        'total_vehicles',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'owner_id', 'user_id');
    }
}
