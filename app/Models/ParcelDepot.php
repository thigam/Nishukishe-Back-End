<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ParcelDepot extends Model
{
    use HasFactory;

    protected $fillable = [
        'sacco_id',
        'name',
        'code',
        'location_details',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class, 'sacco_id', 'sacco_id');
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'agent_depots', 'depot_id', 'user_id');
    }
}
